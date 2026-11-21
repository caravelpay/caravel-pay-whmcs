<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * Connecting to Caravel Pay failed. getMessage() is safe to show to the store admin.
 */
final class ConnectException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    /** invalid_wallet, invalid_url, limit_exceeded, rate_limited, unavailable or error. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
