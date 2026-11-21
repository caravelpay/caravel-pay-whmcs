<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * A platform invoice or order, as the kit sees it.
 */
final class Invoice
{
    public const UNPAID = 'unpaid';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';
    public const OTHER = 'other';

    /** @var string The platform's invoice/order id, as a string. */
    public $ref;
    /** @var string unpaid, paid, cancelled or other. */
    public $status;
    /** @var string Amount due in the invoice's own currency, two decimals. */
    public $nativeAmount;
    /** @var string The invoice's own currency code. */
    public $nativeCurrency;
    /**
     * @var string The amount in US dollars when the platform has one: the invoice is in USD, or the platform
     *             converts it itself. '' otherwise: the invoice is then sent in its own currency, and Caravel
     *             Pay converts it to US dollars when the checkout is created.
     */
    public $usdAmount;
    /** @var string|null Buyer email, when known. */
    public $email;
    /** @var string Platform status as-is, for notes. */
    public $platformStatus;

    public function __construct(string $ref, string $status, string $nativeAmount, string $nativeCurrency, string $usdAmount, ?string $email = null, string $platformStatus = '')
    {
        $this->ref = $ref;
        $this->status = $status;
        $this->nativeAmount = $nativeAmount;
        $this->nativeCurrency = $nativeCurrency;
        $this->usdAmount = $usdAmount;
        $this->email = $email;
        $this->platformStatus = '' !== $platformStatus ? $platformStatus : $status;
    }
}
