<?php

declare(strict_types=1);

namespace CaravelPay;

/**
 * A verified webhook event. Its signature and timestamp were checked; its body is still only a
 * hint: re-fetch the checkout with Client::getCheckout() before you change an order.
 */
final class Event
{
    /** @var string Event id (evt_…). The same on every retry; use it to skip duplicates. */
    public $id;
    /** @var string checkout.paid, checkout.underpaid, checkout.expired or checkout.review. */
    public $type;
    /** @var string ISO 8601 UTC. */
    public $created;
    /** @var array<string, mixed> The checkout object as sent. */
    public $data;
    /** @var string The merchant id that signed the event (Caravel-Merchant). */
    public $merchantId;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $id, string $type, string $created, array $data, string $merchantId)
    {
        $this->id = $id;
        $this->type = $type;
        $this->created = $created;
        $this->data = $data;
        $this->merchantId = $merchantId;
    }

    /** The checkout id the event is about. */
    public function checkoutId(): string
    {
        return (string) $this->data['id'];
    }

    /** The order_id the checkout was created with, when the event carries it. */
    public function orderId(): ?string
    {
        return isset($this->data['order_id']) && is_string($this->data['order_id']) ? $this->data['order_id'] : null;
    }

    /** True for the four checkout.* types this SDK version knows. Acknowledge others with 2xx. */
    public function isKnownType(): bool
    {
        return in_array($this->type, Webhook::EVENT_TYPES, true);
    }

    /** @return array{id: string, type: string, created: string, data: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'created' => $this->created, 'data' => $this->data];
    }
}
