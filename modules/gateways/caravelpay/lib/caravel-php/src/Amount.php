<?php

declare(strict_types=1);

namespace CaravelPay;

/**
 * USD amounts as exact two-decimal strings, without floating-point comparisons.
 */
final class Amount
{
    /**
     * A platform total as the API's two-decimal string: "49.9" → "49.90", 12 → "12.00".
     * Strings may have up to two decimals (more only if they are zeros). Floats are rounded to cents.
     *
     * @param string|int|float $amount
     *
     * @throws \InvalidArgumentException For negative, non-numeric or over-precise values.
     */
    public static function normalize($amount): string
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        } elseif (is_float($amount)) {
            if (!is_finite($amount) || $amount < 0) {
                throw new \InvalidArgumentException('The amount must be a positive number.');
            }
            $amount = number_format(round($amount, 2), 2, '.', '');
        }
        if (!is_string($amount) || !preg_match('/^\s*([0-9]{1,13})(?:\.([0-9]*?)0*)?\s*$/D', $amount, $match)) {
            throw new \InvalidArgumentException('The amount must be a decimal number such as "136.41".');
        }
        $decimals = isset($match[2]) ? $match[2] : '';
        if (strlen($decimals) > 2) {
            throw new \InvalidArgumentException('The amount has more than two decimals.');
        }
        $whole = ltrim($match[1], '0');
        return ('' === $whole ? '0' : $whole) . '.' . str_pad($decimals, 2, '0');
    }

    /** Exact cents as a digit string: "136.41" → "13641". */
    public static function toCents(string $amount): string
    {
        $normalized = self::normalize($amount);
        $cents = ltrim(str_replace('.', '', $normalized), '0');
        return '' === $cents ? '0' : $cents;
    }

    /** -1, 0 or 1, exactly. */
    public static function compare(string $a, string $b): int
    {
        $x = self::toCents($a);
        $y = self::toCents($b);
        if (strlen($x) !== strlen($y)) {
            return strlen($x) < strlen($y) ? -1 : 1;
        }
        return strcmp($x, $y) <=> 0;
    }

    public static function equals(string $a, string $b): bool
    {
        return 0 === self::compare($a, $b);
    }
}
