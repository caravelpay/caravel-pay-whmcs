<?php

declare(strict_types=1);

namespace CaravelPay\Exception;

/**
 * A webhook failed verification. getReason() says why; answer with getHttpStatus() (401, or 400 for
 * a malformed event) and do nothing else.
 */
final class SignatureVerificationException extends CaravelPayException
{
    public const BODY_TOO_LARGE = 'body_too_large';
    public const UNKNOWN_MERCHANT = 'unknown_merchant';
    public const TIMESTAMP_EXPIRED = 'timestamp_expired';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const MALFORMED_EVENT = 'malformed_event';

    /** @var string */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /** One of the class constants, for example "timestamp_expired". */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** The status to answer the webhook with. */
    public function getHttpStatus(): int
    {
        return self::MALFORMED_EVENT === $this->reason ? 400 : 401;
    }
}
