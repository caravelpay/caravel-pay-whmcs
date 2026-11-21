<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * What a billing platform does when a checkout changes. One small adapter per platform.
 */
interface Ledger
{
    /** The invoice as it is now, or null when it doesn't exist. */
    public function invoice(string $ref): ?Invoice;

    /**
     * Record a confirmed payment: mark the invoice paid with the payout transaction hash.
     *
     * @param array<string, mixed> $checkout     The re-fetched, validated checkout.
     * @param string               $nativeAmount The invoice-currency amount this checkout was created for.
     * @param string               $fee          The 2% Caravel fee in the invoice currency.
     *
     * @return bool False when the platform could not record it (the webhook is then retried).
     */
    public function recordPayment(Invoice $invoice, array $checkout, string $txHash, string $nativeAmount, string $fee): bool;

    /**
     * A status that needs the merchant's attention but must not mark the invoice paid:
     * underpaid, review, expired, or a payment for an invoice that is no longer open.
     */
    public function flag(Invoice $invoice, string $status, string $message): void;

    /** Write to the platform's payment/gateway log. $level: info, warning or error. */
    public function log(string $level, string $message, array $context = []): void;
}
