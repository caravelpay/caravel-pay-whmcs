<?php

declare(strict_types=1);

namespace CaravelPay\Exception;

/**
 * The API answered with a non-2xx status and (usually) an {"error": {"code", "message", "param"?}} body.
 * Branch on getErrorCode(); the message is for humans and may change.
 */
final class ApiException extends CaravelPayException
{
    /** @var int */
    private $httpStatus;
    /** @var string */
    private $errorCode;
    /** @var string|null */
    private $param;
    /** @var int|null */
    private $retryAfter;

    public function __construct(string $message, int $httpStatus, string $errorCode, ?string $param = null, ?int $retryAfter = null)
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->param = $param;
        $this->retryAfter = $retryAfter;
    }

    /**
     * Build the exception from a failed response.
     *
     * @param int         $status     HTTP status.
     * @param mixed       $decoded    Decoded JSON body (or null).
     * @param string|null $retryAfter Retry-After header.
     */
    public static function fromResponse(int $status, $decoded, ?string $retryAfter): self
    {
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $code = isset($error['code']) && is_string($error['code']) && preg_match('/^[a-z0-9_]{1,64}$/D', $error['code'])
            ? $error['code']
            : ($status >= 500 ? 'server_error' : 'http_' . $status);
        $message = isset($error['message']) && is_string($error['message']) ? self::clean($error['message']) : '';
        if ('' === $message) {
            $message = 'Caravel Pay returned HTTP ' . $status . ' (' . $code . ').';
        }
        $param = isset($error['param']) && is_string($error['param']) && '' !== $error['param'] ? self::clean($error['param']) : null;
        $wait = null;
        if (null !== $retryAfter && preg_match('/^[0-9]{1,6}$/D', trim($retryAfter))) {
            $wait = (int) trim($retryAfter);
        } elseif (isset($error['retry_after']) && is_int($error['retry_after']) && $error['retry_after'] >= 0) {
            $wait = $error['retry_after'];
        }
        return new self($message, $status, $code, $param, $wait);
    }

    /** HTTP status of the response, for example 409. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The error code, for example "order_id_conflict". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The field, header or query parameter the error is about, when there is one. */
    public function getParam(): ?string
    {
        return $this->param;
    }

    /** Seconds to wait before retrying (Retry-After), when the API sent one. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** True for errors that can succeed if the same request is sent again later (429 and 5xx). */
    public function isRetryable(): bool
    {
        return 429 === $this->httpStatus || $this->httpStatus >= 500;
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        return strlen($text) > 300 ? substr($text, 0, 300) : $text;
    }
}
