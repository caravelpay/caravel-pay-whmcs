<?php

declare(strict_types=1);

namespace CaravelPay\Http;

/**
 * Sends one HTTP request. The SDK ships CurlTransport; platforms with their own HTTP client
 * (WordPress, Laravel, Magento…) can pass an adapter instead. A transport must not follow
 * redirects, must verify TLS, and must throw NetworkException when there is no HTTP response.
 */
interface TransportInterface
{
    /**
     * @param string               $method         GET or POST.
     * @param string               $url            Absolute URL.
     * @param array<string,string> $headers        Request headers.
     * @param string               $body           Raw body ('' for none).
     * @param int                  $timeout        Total timeout, seconds.
     * @param int                  $connectTimeout Connect timeout, seconds.
     *
     * @throws \CaravelPay\Exception\NetworkException
     */
    public function send(string $method, string $url, array $headers, string $body, int $timeout, int $connectTimeout): Response;
}
