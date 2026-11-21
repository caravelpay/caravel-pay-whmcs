<?php

declare(strict_types=1);

namespace CaravelPay;

/**
 * Who pays the fees for a checkout (`fee_mode`).
 *
 * | fee_mode         | card provider's fee | 2% Caravel fee | on a 100.00 order you receive |
 * |------------------|---------------------|----------------|-------------------------------|
 * | seller (default) | you                 | you            | what arrives minus 2.00       |
 * | split            | the buyer           | you            | 98.00                         |
 * | buyer            | the buyer           | the buyer      | 100.00                        |
 *
 * Send it on a checkout (or as your merchant default when connecting). A checkout's `amount` is
 * always your own order total; a split or buyer checkout also carries `fee_mode` and
 * `buyer_total_estimate`, and a seller checkout carries neither.
 *
 * Card surcharges are restricted in some countries (for example on consumer cards in the EU and
 * the UK), so check your local rules before passing fees on.
 */
final class FeeMode
{
    public const SELLER = 'seller';
    public const SPLIT = 'split';
    public const BUYER = 'buyer';
    public const ALL = [self::SELLER, self::SPLIT, self::BUYER];

    /** True for one of the three modes. */
    public static function isValid($value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }

    /** A saved setting as a mode: anything that isn't split or buyer is the default, seller. */
    public static function normalize($value): string
    {
        return self::SPLIT === $value || self::BUYER === $value ? $value : self::SELLER;
    }

    /**
     * Labels for a "Who pays the fees" setting, in display order.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SELLER => 'Seller pays everything (default)',
            self::SPLIT => 'Split: buyer pays the card fee',
            self::BUYER => 'Buyer pays everything',
        ];
    }

    /** Help text for that setting. */
    public static function help(): string
    {
        return 'Seller pays everything: your buyer pays the order total, and the card provider\'s fee and the 2% Caravel fee come off what you receive. '
            . 'Split: the card provider\'s fee is added to your buyer\'s total, and you pay the 2% Caravel fee. '
            . 'Buyer pays everything: the 2% Caravel fee is added to your buyer\'s total too, so you receive the full order total. '
            . 'Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules before passing fees on.';
    }

    /** One line for buyers before they pay, or '' when the seller pays everything. */
    public static function buyerNotice($mode): string
    {
        $mode = self::normalize($mode);
        if (self::SPLIT === $mode) {
            return 'The card provider adds its fee to your total at payment. You see it before you pay.';
        }
        if (self::BUYER === $mode) {
            return 'A processing fee and the card provider\'s fee are added to your total at payment. You see them before you pay.';
        }
        return '';
    }

    /**
     * Add `fee_mode` to a create-checkout request for a saved setting. Nothing is added for seller, so a
     * default installation sends exactly the same request as before the setting existed.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function apply(array $body, $mode): array
    {
        $mode = self::normalize($mode);
        if (self::SELLER !== $mode) {
            $body['fee_mode'] = $mode;
        }
        return $body;
    }

    /**
     * The mode of a checkout object: its `fee_mode`, or seller when it has none.
     *
     * @param array<string, mixed> $checkout
     */
    public static function of(array $checkout): string
    {
        return self::normalize(isset($checkout['fee_mode']) ? $checkout['fee_mode'] : self::SELLER);
    }
}
