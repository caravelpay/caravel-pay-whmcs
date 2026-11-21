<?php

declare(strict_types=1);

namespace CaravelPay;

use CaravelPay\Exception\SignatureVerificationException;

/**
 * Verify webhooks from Caravel Pay.
 *
 *     $event = Webhook::verify($rawBody, $headers, $secret, 'https://shop.example/webhooks/caravel');
 *
 * Checks, in order: body size, merchant, timestamp (±300 s), then the HMAC over
 * "{timestamp}\nPOST\n{callback path + query}\n{raw body}" with a constant-time comparison, then the
 * event shape. After it returns, re-fetch the checkout before changing an order: the webhook says
 * what to look at, GET /v1/checkouts/{id} says what is true.
 */
final class Webhook
{
    public const MAX_BODY = 262144;
    public const USER_AGENT = 'CaravelPay-Webhooks/1';
    public const EVENT_TYPES = ['checkout.paid', 'checkout.underpaid', 'checkout.expired', 'checkout.review'];
    public const EVENT_ID_PATTERN = '/^evt_[A-Za-z0-9_-]{4,128}$/D';

    /**
     * @param string                                  $payload     The raw request body, unparsed.
     * @param array<string, string|string[]>          $headers     Request headers in any case; $_SERVER-style
     *                                                             HTTP_CARAVEL_SIGNATURE keys work too.
     * @param string|array<string, string>|callable   $secrets     The secret; or merchant id => secret (keep
     *                                                             replaced credentials until their checkouts
     *                                                             finish); or fn(string $merchantId): ?string.
     * @param string|string[]                         $callbackUrl The callback_url you registered (full URL or
     *                                                             path + query), or several equivalent forms.
     * @param array{tolerance?: int, now?: int, merchant_id?: string} $options
     *        tolerance: seconds (default 300); now: unix time (tests);
     *        merchant_id: with a single secret, require this Caravel-Merchant.
     *
     * @throws SignatureVerificationException
     */
    public static function verify(string $payload, array $headers, $secrets, $callbackUrl, array $options = []): Event
    {
        $headers = self::normalizeHeaders($headers);
        $tolerance = isset($options['tolerance']) ? max(1, (int) $options['tolerance']) : Signature::TOLERANCE;
        $now = isset($options['now']) ? (int) $options['now'] : time();

        if (strlen($payload) > self::MAX_BODY) {
            throw new SignatureVerificationException(SignatureVerificationException::BODY_TOO_LARGE, 'The webhook body is too large.');
        }
        $merchant = isset($headers['caravel-merchant']) ? $headers['caravel-merchant'] : '';
        $secret = '' !== $merchant && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $merchant) ? self::secretFor($secrets, $merchant, $options) : '';
        if ('' === $secret) {
            throw new SignatureVerificationException(SignatureVerificationException::UNKNOWN_MERCHANT, 'The webhook is not for a known merchant.');
        }
        $timestamp = isset($headers['caravel-timestamp']) ? $headers['caravel-timestamp'] : '';
        if (!Signature::isFresh($timestamp, $now, $tolerance)) {
            throw new SignatureVerificationException(SignatureVerificationException::TIMESTAMP_EXPIRED, 'The webhook timestamp is missing or outside the allowed window.');
        }
        $signature = isset($headers['caravel-signature']) ? $headers['caravel-signature'] : '';
        $valid = false;
        if (preg_match('/^[0-9a-f]{64}$/D', $signature)) {
            foreach (self::paths($callbackUrl) as $path) {
                // Compare every candidate, so timing doesn't reveal which one matched.
                if (hash_equals(Signature::compute($secret, $timestamp, 'POST', $path, $payload), $signature)) {
                    $valid = true;
                }
            }
        }
        if (!$valid) {
            throw new SignatureVerificationException(SignatureVerificationException::INVALID_SIGNATURE, 'The webhook signature does not match.');
        }
        return self::event($payload, $merchant, isset($headers['caravel-event']) ? $headers['caravel-event'] : '');
    }

    /**
     * verify() for the current PHP request: reads php://input and the request headers.
     *
     * @param string|array<string, string>|callable $secrets
     * @param string|string[]                       $callbackUrl
     * @param array<string, mixed>                  $options
     *
     * @throws SignatureVerificationException
     */
    public static function verifyCurrentRequest($secrets, $callbackUrl, array $options = []): Event
    {
        return self::verify(self::readBody(), self::headersFromServer($_SERVER), $secrets, $callbackUrl, $options);
    }

    /** The raw request body, read once, at most MAX_BODY + 1 bytes. */
    public static function readBody(): string
    {
        $body = @file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        return is_string($body) ? $body : '';
    }

    /**
     * Caravel-* headers from a $_SERVER-style array.
     *
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach (['caravel-merchant', 'caravel-timestamp', 'caravel-signature', 'caravel-event', 'user-agent'] as $name) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }
        return $headers;
    }

    /**
     * Headers to send a signed test event to your own endpoint (what Caravel Pay sends).
     *
     * @return array<string, string>
     */
    public static function signTestEvent(string $merchantId, string $secret, string $callbackUrl, string $body, ?int $timestamp = null): array
    {
        $decoded = json_decode($body, true);
        $headers = Signature::headers($merchantId, $secret, 'POST', Signature::signedPath($callbackUrl), $body, null === $timestamp ? time() : $timestamp);
        $headers['Content-Type'] = 'application/json';
        $headers['User-Agent'] = self::USER_AGENT;
        if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
            $headers['Caravel-Event'] = $decoded['id'];
        }
        return $headers;
    }

    /**
     * @param string|array<string, string>|callable $secrets
     * @param array<string, mixed>                  $options
     */
    private static function secretFor($secrets, string $merchant, array $options): string
    {
        if (is_string($secrets)) {
            $required = isset($options['merchant_id']) ? (string) $options['merchant_id'] : '';
            return '' === $required || hash_equals($required, $merchant) ? $secrets : '';
        }
        if (is_array($secrets)) {
            return isset($secrets[$merchant]) && is_string($secrets[$merchant]) ? $secrets[$merchant] : '';
        }
        if (is_callable($secrets)) {
            $secret = call_user_func($secrets, $merchant);
            return is_string($secret) ? $secret : '';
        }
        throw new \InvalidArgumentException('Pass the secret as a string, a merchant id => secret array or a callable.');
    }

    /**
     * @param string|string[] $callbackUrl
     *
     * @return string[]
     */
    private static function paths($callbackUrl): array
    {
        $urls = is_array($callbackUrl) ? $callbackUrl : [$callbackUrl];
        $paths = [];
        foreach ($urls as $url) {
            if (!is_string($url) || '' === $url) {
                throw new \InvalidArgumentException('Pass the callback URL (or its path) that you registered with the checkout.');
            }
            $paths[] = Signature::signedPath($url);
        }
        if (!$paths) {
            throw new \InvalidArgumentException('Pass at least one callback URL.');
        }
        return array_values(array_unique($paths));
    }

    /**
     * @throws SignatureVerificationException
     */
    private static function event(string $payload, string $merchant, string $headerEvent): Event
    {
        $decoded = preg_match('/^\s*\{/', $payload) ? json_decode($payload, true, 64) : null;
        $id = is_array($decoded) && isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : '';
        $type = is_array($decoded) && isset($decoded['type']) && is_string($decoded['type']) ? $decoded['type'] : '';
        $created = is_array($decoded) && isset($decoded['created']) && is_string($decoded['created']) ? $decoded['created'] : '';
        $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : null;
        if (!preg_match(self::EVENT_ID_PATTERN, $id) || !preg_match('/^[a-z_]{1,32}\.[a-z_]{1,32}$/D', $type) || null === $data
            || !isset($data['id']) || !is_string($data['id']) || !preg_match(Checkout::ID_PATTERN, $data['id'])
            || ('' !== $headerEvent && $headerEvent !== $id)) {
            throw new SignatureVerificationException(SignatureVerificationException::MALFORMED_EVENT, 'The webhook body is not a Caravel Pay event.');
        }
        return new Event($id, $type, $created, $data, $merchant);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (0 === strpos($name, 'http_')) {
                $name = substr($name, 5);
            }
            $name = str_replace('_', '-', $name);
            if (is_array($value)) {
                $value = reset($value);
            }
            if (is_string($value)) {
                $out[$name] = trim($value);
            }
        }
        return $out;
    }
}
