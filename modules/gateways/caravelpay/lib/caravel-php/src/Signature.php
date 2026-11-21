<?php

declare(strict_types=1);

namespace CaravelPay;

/**
 * Caravel Pay request and webhook signatures.
 *
 *     signature = lowercase hex HMAC-SHA256(secret as ASCII, "{timestamp}\n{METHOD}\n{path}\n{rawBody}")
 *
 * `path` is the request path plus its query string when one is present, never the full URL.
 */
final class Signature
{
    /** Allowed clock difference, seconds. */
    public const TOLERANCE = 300;

    /** The exact string that is signed. */
    public static function stringToSign(string $timestamp, string $method, string $path, string $body): string
    {
        return $timestamp . "\n" . strtoupper($method) . "\n" . $path . "\n" . $body;
    }

    /** Lowercase hex HMAC-SHA256 of the string to sign. */
    public static function compute(string $secret, string $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', self::stringToSign($timestamp, $method, $path, $body), $secret);
    }

    /**
     * The three Caravel-* headers for a request.
     *
     * @return array<string, string>
     */
    public static function headers(string $merchantId, string $secret, string $method, string $path, string $body, int $timestamp): array
    {
        $ts = (string) $timestamp;
        return [
            'Caravel-Merchant' => $merchantId,
            'Caravel-Timestamp' => $ts,
            'Caravel-Signature' => self::compute($secret, $ts, $method, $path, $body),
        ];
    }

    /**
     * Constant-time check of a received signature (64 lowercase hex characters).
     */
    public static function matches(string $signature, string $secret, string $timestamp, string $method, string $path, string $body): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $signature)) {
            return false;
        }
        return hash_equals(self::compute($secret, $timestamp, $method, $path, $body), $signature);
    }

    /**
     * True when the timestamp is unix seconds within the tolerance of $now.
     */
    public static function isFresh(string $timestamp, int $now, int $tolerance = self::TOLERANCE): bool
    {
        return (bool) preg_match('/^[0-9]{1,12}$/D', $timestamp) && abs($now - (int) $timestamp) <= $tolerance;
    }

    /**
     * The signed path of a URL: its path plus "?query" when the query is not empty, with the
     * normalisation a WHATWG URL parser applies (empty path → "/", dot segments resolved, spaces,
     * quotes, angle brackets and non-ASCII bytes percent-encoded). A value that starts with a single
     * "/" is treated as a path that may carry a query. Fragments are dropped.
     *
     * Examples: "https://shop.example/webhooks/caravel?shop=main" → "/webhooks/caravel?shop=main",
     * "https://shop.example" → "/", "/v1/checkouts?" → "/v1/checkouts".
     *
     * @throws \InvalidArgumentException For anything other than an http(s) URL or an absolute path.
     */
    public static function signedPath(string $url): string
    {
        $hash = strpos($url, '#');
        if (false !== $hash) {
            $url = substr($url, 0, $hash);
        }
        if (preg_match('#^/(?!/)#', $url) || '/' === $url) {
            $rest = $url;
        } elseif (preg_match('#^(https?)://[^/?\\\\]+(.*)$#iD', $url, $match)) {
            $rest = $match[2];
        } else {
            throw new \InvalidArgumentException('Expected an http(s) URL or a path starting with "/".');
        }
        $question = strpos($rest, '?');
        $path = false === $question ? $rest : substr($rest, 0, $question);
        $query = false === $question ? '' : substr($rest, $question + 1);
        $path = str_replace('\\', '/', $path);
        if ('' === $path) {
            $path = '/';
        }
        $path = self::removeDotSegments(self::encode($path, '"<>`{}'));
        return '' === $query ? $path : $path . '?' . self::encode($query, "\"<>'");
    }

    private static function encode(string $value, string $extra): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            $out .= $byte < 0x21 || $byte > 0x7e || false !== strpos($extra, $value[$i]) ? sprintf('%%%02X', $byte) : $value[$i];
        }
        return $out;
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', substr($path, 1));
        $last = count($segments) - 1;
        $out = [];
        foreach ($segments as $index => $segment) {
            $lower = strtolower($segment);
            if (in_array($lower, ['..', '.%2e', '%2e.', '%2e%2e'], true)) {
                array_pop($out);
                if ($index === $last) {
                    $out[] = '';
                }
            } elseif (in_array($lower, ['.', '%2e'], true)) {
                if ($index === $last) {
                    $out[] = '';
                }
            } else {
                $out[] = $segment;
            }
        }
        return '/' . implode('/', $out);
    }
}
