<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * Where the kit keeps credentials, checkouts, processed events, settings and short locks.
 * PdoStorage implements it on the platform's own database.
 */
interface Storage
{
    /** @return array<int, array<string, mixed>> Every credential row, newest first: merchant_id, secret, payout_address, site_url, status (current|retired), created_at, retired_at. */
    public function credentials(): array;

    /** @param array<string, mixed> $row */
    public function addCredentials(array $row): void;

    public function retireCredentials(string $merchantId, int $at): void;

    public function deleteCredentials(string $merchantId): void;

    /** @return array<string, mixed>|null Per-invoice state: invoice_ref, salt, attempt, current_checkout, pending (array|null). */
    public function invoiceState(string $ref): ?array;

    /** @param array<string, mixed> $state */
    public function saveInvoiceState(array $state): void;

    /** @return array<string, mixed>|null A checkout row by checkout id. */
    public function checkout(string $checkoutId): ?array;

    /** @param array<string, mixed> $row Insert or replace by checkout_id. */
    public function saveCheckout(array $row): void;

    /** Unix time of the newest checkout created with this merchant, or 0. */
    public function latestCheckoutAt(string $merchantId): int;

    public function eventSeen(string $eventId): bool;

    public function rememberEvent(string $eventId, int $at): void;

    public function forgetEventsBefore(int $at): void;

    public function setting(string $name): ?string;

    public function saveSetting(string $name, string $value): void;

    /** A short exclusive lock. Returns a token, or null when someone else holds it. */
    public function lock(string $name, int $ttlSeconds, int $now): ?string;

    public function unlock(string $name, string $token): void;
}
