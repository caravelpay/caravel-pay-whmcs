<?php

declare(strict_types=1);

namespace CaravelPay;

use CaravelPay\Exception\UnexpectedResponseException;

/**
 * Checkout statuses and validation of checkout objects returned by the API.
 *
 * | status           | meaning                                                         | suggested order state |
 * |------------------|-----------------------------------------------------------------|-----------------------|
 * | awaiting_payment | open, nothing arrived yet                                       | unpaid                |
 * | confirming       | a payment was seen and is confirming; don't ship yet            | unpaid                |
 * | paid             | full amount arrived and both payout transfers confirmed         | paid (use the tx hash)|
 * | underpaid        | less than the amount arrived; the buyer can still top up        | on hold, note         |
 * | expired          | passed expires_at without payment                               | cancelled / unpaid    |
 * | review           | needs a manual look (for example a payment after expiry)        | on hold, note         |
 *
 * Late transitions exist: underpaid → paid, expired → paid and any status → review.
 */
final class Checkout
{
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const CONFIRMING = 'confirming';
    public const PAID = 'paid';
    public const UNDERPAID = 'underpaid';
    public const EXPIRED = 'expired';
    public const REVIEW = 'review';
    public const STATUSES = [self::AWAITING_PAYMENT, self::CONFIRMING, self::PAID, self::UNDERPAID, self::EXPIRED, self::REVIEW];

    public const ID_PATTERN = '/^chk_[A-Za-z0-9_-]{4,128}$/D';
    private const AMOUNT = '/^[0-9]{1,13}\.[0-9]{2}$/D';
    /** `amount` in the checkout's own currency: two decimals, or none (JPY, KRW). */
    private const OWN_AMOUNT = '/^[0-9]{1,13}(?:\.[0-9]{2})?$/D';
    /** `fx_rate`: US dollars for one unit of the checkout's currency. */
    public const RATE_PATTERN = '/^[0-9]{1,9}\.[0-9]{1,18}$/D';
    private const TIME = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,3})?Z$/D';
    private const TX_HASH = '/^0x[0-9a-fA-F]{64}$/D';
    private const ASSET = '/^[A-Za-z][A-Za-z0-9.]{0,15}$/D';
    /** Ethereum payout assets (every other payout asset is on Polygon). */
    public const ETHEREUM_ASSETS = ['PYUSD', 'USDT', 'ETH'];

    /**
     * Validate a checkout object. Unknown fields are kept, so newer API fields pass through.
     *
     * @param mixed  $value      Decoded JSON.
     * @param string $expectedId When set, the checkout id must equal it.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedResponseException
     */
    public static function validate($value, string $expectedId = ''): array
    {
        if (!is_array($value)) {
            throw new UnexpectedResponseException('Caravel Pay returned something other than a checkout object.');
        }
        $id = isset($value['id']) && is_string($value['id']) ? $value['id'] : '';
        if (!preg_match(self::ID_PATTERN, $id) || ('' !== $expectedId && !hash_equals($expectedId, $id))) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with an unexpected id.');
        }
        $status = isset($value['status']) ? $value['status'] : null;
        if (!in_array($status, self::STATUSES, true)) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with an unknown status.');
        }
        $currency = isset($value['currency']) && is_string($value['currency']) ? $value['currency'] : '';
        if (!isset($value['amount']) || !is_string($value['amount']) || !preg_match('/^[A-Z]{3}$/D', $currency)
            || !preg_match(Currency::USD === $currency ? self::AMOUNT : self::OWN_AMOUNT, $value['amount'])
            || (Currency::isSupported($currency) && (0 === Currency::decimals($currency)) !== (false === strpos($value['amount'], '.')))) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed amount or currency.');
        }
        // Another currency: the order total in US dollars and the rate are fixed with the checkout and always present.
        // A US dollar checkout carries neither.
        $converted = Currency::USD !== $currency;
        if ($converted !== isset($value['usd_amount']) || $converted !== isset($value['fx_rate'])
            || ($converted && (!is_string($value['usd_amount']) || !preg_match(self::AMOUNT, $value['usd_amount']) || !is_string($value['fx_rate']) || !preg_match(self::RATE_PATTERN, $value['fx_rate'])))
            || (isset($value['fx_buffer_bps']) && (!$converted || !is_int($value['fx_buffer_bps']) || $value['fx_buffer_bps'] < 1 || $value['fx_buffer_bps'] > Currency::MAX_BUFFER_BPS))) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with malformed conversion fields.');
        }
        foreach (['checkout_url', 'order_id'] as $field) {
            if (isset($value[$field]) && !is_string($value[$field])) {
                throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed ' . $field . '.');
            }
        }
        foreach (['expires_at', 'created_at'] as $field) {
            if (isset($value[$field]) && (!is_string($value[$field]) || !preg_match(self::TIME, $value[$field]))) {
                throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed ' . $field . '.');
            }
        }
        if (isset($value['paid_amount']) && (!is_string($value['paid_amount']) || !preg_match(self::AMOUNT, $value['paid_amount']))) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed paid_amount.');
        }
        // Who pays the fees: present only when the buyer pays a fee. ("seller" is accepted too, should the API ever name it.)
        if (isset($value['fee_mode']) && !FeeMode::isValid($value['fee_mode'])) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with an unknown fee_mode.');
        }
        if (isset($value['buyer_total_estimate']) && (!is_string($value['buyer_total_estimate']) || !preg_match(self::AMOUNT, $value['buyer_total_estimate']))) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed buyer_total_estimate.');
        }
        if (isset($value['payout'])) {
            self::payout($value['payout']);
        }
        if (isset($value['payouts'])) {
            if (!is_array($value['payouts'])) {
                throw new UnexpectedResponseException('Caravel Pay returned a checkout with malformed payouts.');
            }
            foreach ($value['payouts'] as $payout) {
                self::payout($payout);
            }
        }
        return $value;
    }

    /** True when the checkout is paid: the full amount arrived and both payout legs confirmed. */
    public static function isPaid(array $checkout): bool
    {
        return isset($checkout['status']) && self::PAID === $checkout['status'];
    }

    /**
     * The order total in US dollars: `usd_amount` for a checkout priced in another currency (fixed when
     * it was created), `amount` for a US dollar checkout. `paid_amount`, `buyer_total_estimate` and the
     * payout are always in US dollars or tokens; compare them with this, never with `amount`.
     */
    public static function usdAmount(array $checkout): ?string
    {
        $value = isset($checkout['usd_amount']) ? $checkout['usd_amount'] : (isset($checkout['currency'], $checkout['amount']) && Currency::USD === $checkout['currency'] ? $checkout['amount'] : null);
        return is_string($value) && preg_match(self::AMOUNT, $value) ? $value : null;
    }

    /** US dollars for one unit of the checkout's currency when it was created; null for a US dollar checkout. */
    public static function fxRate(array $checkout): ?string
    {
        $value = isset($checkout['fx_rate']) ? $checkout['fx_rate'] : null;
        return is_string($value) && preg_match(self::RATE_PATTERN, $value) ? $value : null;
    }

    /** Who pays the fees for this checkout: "seller" (no fee_mode on the object), "split" or "buyer". */
    public static function feeMode(array $checkout): string
    {
        return FeeMode::of($checkout);
    }

    /**
     * What the buyer is asked to pay before the card provider's own fee, for a split or buyer checkout:
     * `amount` for split, `amount` plus the 2% Caravel fee for buyer. Null for a seller checkout.
     */
    public static function buyerTotalEstimate(array $checkout): ?string
    {
        $value = isset($checkout['buyer_total_estimate']) ? $checkout['buyer_total_estimate'] : null;
        return is_string($value) && preg_match(self::AMOUNT, $value) ? $value : null;
    }

    /** The payout transaction hash, lowercase, or null before payout. */
    public static function txHash(array $checkout): ?string
    {
        $hash = isset($checkout['payout']['tx_hash']) ? $checkout['payout']['tx_hash'] : null;
        return is_string($hash) && preg_match(self::TX_HASH, $hash) ? strtolower($hash) : null;
    }

    /**
     * Every payout transfer, oldest first: `payouts` when the payment went out as several
     * transfers, otherwise `[payout]`, otherwise [].
     *
     * @return array<int, array{amount: string, asset: string, tx_hash: string}>
     */
    public static function payouts(array $checkout): array
    {
        if (isset($checkout['payouts']) && is_array($checkout['payouts']) && count($checkout['payouts']) > 1) {
            return array_values($checkout['payouts']);
        }
        return isset($checkout['payout']) && is_array($checkout['payout']) ? [$checkout['payout']] : [];
    }

    /**
     * Block-explorer link for a payout transaction hash, or ''.
     * Defaults to Etherscan for Ethereum payout assets (PYUSD, USDT, ETH) and Polygonscan otherwise.
     * Pass $prefix to override the explorer base URL (or pass an asset ticker when $asset is omitted).
     */
    public static function explorerUrl(?string $txHash, ?string $prefix = null, ?string $asset = null): string
    {
        if (null === $txHash || !preg_match(self::TX_HASH, $txHash)) {
            return '';
        }
        if (null !== $prefix && '' !== $prefix) {
            if ((null === $asset || '' === $asset) && preg_match(self::ASSET, trim($prefix))) {
                $asset = $prefix;
                $prefix = null;
            }
        } else {
            $prefix = null;
        }
        if (null === $prefix) {
            $normalized = null !== $asset ? strtoupper(trim($asset)) : '';
            $prefix = in_array($normalized, self::ETHEREUM_ASSETS, true)
                ? 'https://etherscan.io/tx/'
                : 'https://polygonscan.com/tx/';
        }
        return $prefix . strtolower($txHash);
    }

    /**
     * @param mixed $payout
     *
     * @throws UnexpectedResponseException
     */
    private static function payout($payout): void
    {
        if (!is_array($payout)
            || !isset($payout['amount']) || !is_string($payout['amount']) || !preg_match('/^[0-9]{1,30}(?:\.[0-9]{1,18})?$/D', $payout['amount'])
            || !isset($payout['asset']) || !is_string($payout['asset']) || !preg_match('/^[A-Za-z][A-Za-z0-9.]{0,15}$/D', $payout['asset'])
            || !isset($payout['tx_hash']) || !is_string($payout['tx_hash']) || !preg_match(self::TX_HASH, $payout['tx_hash'])) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with a malformed payout.');
        }
    }
}
