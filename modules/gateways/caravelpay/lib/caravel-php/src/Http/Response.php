<?php

declare(strict_types=1);

namespace CaravelPay\Http;

/**
 * A raw HTTP response from a transport.
 */
final class Response
{
    /** @var int */
    private $status;
    /** @var array<string, string> */
    private $headers;
    /** @var string */
    private $body;

    /**
     * @param int                  $status  HTTP status.
     * @param array<string,string> $headers Response headers (any case).
     * @param string               $body    Raw body.
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** A header value by case-insensitive name, or null. */
    public function header(string $name): ?string
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    /** @return array<string, string> Lowercase header names. */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}
