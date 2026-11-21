<?php

declare(strict_types=1);

namespace CaravelPay\Http;

use CaravelPay\Exception\NetworkException;

/**
 * The default transport: ext-curl, TLS verified, no redirects, response size capped.
 */
final class CurlTransport implements TransportInterface
{
    public const DEFAULT_MAX_BYTES = 1048576;

    /** @var string|null */
    private $caBundle;
    /** @var int */
    private $maxBytes;

    /**
     * @param array{ca_bundle?: string|null, max_bytes?: int} $options
     *        ca_bundle: path to a CA bundle, for hosts whose system bundle is outdated.
     *        max_bytes: largest response body accepted (default 1 MiB).
     */
    public function __construct(array $options = [])
    {
        $this->caBundle = isset($options['ca_bundle']) && '' !== (string) $options['ca_bundle'] ? (string) $options['ca_bundle'] : null;
        $this->maxBytes = isset($options['max_bytes']) ? max(1024, (int) $options['max_bytes']) : self::DEFAULT_MAX_BYTES;
    }

    public function send(string $method, string $url, array $headers, string $body, int $timeout, int $connectTimeout): Response
    {
        if (!function_exists('curl_init')) {
            throw new NetworkException('The PHP curl extension is required to reach Caravel Pay.');
        }
        $lines = ['Expect:'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $buffer = '';
        $tooLarge = false;
        $max = $this->maxBytes;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$responseHeaders) {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                    $responseHeaders = []; // A new response (for example after 100 Continue).
                } else {
                    $parts = explode(':', $line, 2);
                    if (2 === count($parts)) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$buffer, &$tooLarge, $max) {
                if (strlen($buffer) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if ('' !== $body) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        if (null !== $this->caBundle) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        $handle = curl_init();
        if (false === $handle) {
            throw new NetworkException('Could not start a curl request.');
        }
        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new NetworkException('Could not configure the curl request.');
            }
            $ok = curl_exec($handle);
            if ($tooLarge) {
                throw new NetworkException('The Caravel Pay response was larger than ' . $max . ' bytes.');
            }
            if (false === $ok) {
                throw new NetworkException('Caravel Pay could not be reached (' . curl_error($handle) . ').', (int) curl_errno($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            if (PHP_VERSION_ID < 80000) {
                curl_close($handle);
            }
        }
        if ($status < 100) {
            throw new NetworkException('Caravel Pay sent no HTTP status.');
        }
        return new Response($status, $responseHeaders, $buffer);
    }
}
