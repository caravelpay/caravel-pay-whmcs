<?php

declare(strict_types=1);

namespace CaravelPay;

/**
 * Currencies a checkout can be priced in.
 *
 * Send `amount` and `currency` as your store has them. Anything but USD is converted to US dollars
 * when the checkout is created, and the rate is fixed for that checkout: buyers are always charged
 * in US dollars and you are always paid in USDC. The checkout keeps your `amount` and `currency`
 * and adds `usd_amount` (your order total in US dollars) and `fx_rate` (US dollars for one unit).
 *
 * Amounts use the currency's own decimals: two for most ("45.00"), none for JPY and KRW ("5000").
 */
final class Currency
{
    public const USD = 'USD';

    /** Supported currencies and their decimals. */
    public const DECIMALS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2, 'NZD' => 2, 'SEK' => 2, 'NOK' => 2, 'DKK' => 2, 'CHF' => 2,
        'PLN' => 2, 'CZK' => 2, 'HUF' => 2, 'RON' => 2, 'HKD' => 2, 'SGD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2, 'ILS' => 2,
        'INR' => 2, 'THB' => 2, 'MYR' => 2, 'PHP' => 2, 'TRY' => 2, 'JPY' => 0, 'KRW' => 0,
    ];

    /** The largest margin (`fx_buffer_bps`) a checkout accepts: 500 basis points, 5%. */
    public const MAX_BUFFER_BPS = 500;

    /** True for a currency a checkout can be priced in (upper case, as in ISO 4217). */
    public static function isSupported($code): bool
    {
        return is_string($code) && isset(self::DECIMALS[$code]);
    }

    /**
     * A platform's currency code as the API's: trimmed and upper case ("eur" → "EUR"). '' when it
     * isn't a three-letter code.
     */
    public static function code($code): string
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';
        return preg_match('/^[A-Z]{3}$/D', $code) ? $code : '';
    }

    /** 2 for most currencies, 0 for JPY and KRW. Unknown codes count as 2. */
    public static function decimals(string $currency): int
    {
        return isset(self::DECIMALS[$currency]) ? self::DECIMALS[$currency] : 2;
    }

    /**
     * A platform total as the API's string in this currency's own decimals:
     * "49.9" → "49.90" for EUR, 5000 or "5000.00" → "5000" for JPY.
     *
     * @param string|int|float $amount
     *
     * @throws \InvalidArgumentException For negative, non-numeric or over-precise values ("5000.50" JPY).
     */
    public static function format($amount, string $currency): string
    {
        if (0 !== self::decimals($currency)) {
            return Amount::normalize($amount);
        }
        if (is_float($amount)) {
            if (!is_finite($amount) || $amount < 0) {
                throw new \InvalidArgumentException('The amount must be a positive number.');
            }
            $amount = number_format(round($amount), 0, '.', '');
        } elseif (is_int($amount)) {
            $amount = (string) $amount;
        }
        if (!is_string($amount) || !preg_match('/^\s*([0-9]{1,13})(?:\.0*)?\s*$/D', $amount, $match)) {
            throw new \InvalidArgumentException('An amount in ' . $currency . ' must be a whole number such as "5000": ' . $currency . ' has no decimals.');
        }
        $whole = ltrim($match[1], '0');
        return '' === $whole ? '0' : $whole;
    }

    /** True when the string is already an API amount in this currency's decimals, above zero. */
    public static function isAmount($value, string $currency): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $pattern = 0 === self::decimals($currency) ? '/^[1-9][0-9]{0,11}$/D' : '/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D';
        return (bool) preg_match($pattern, $value) && '' !== trim($value, '0.');
    }

    /**
     * A saved "exchange-rate buffer" setting as whole basis points, 0 to MAX_BUFFER_BPS. Takes a
     * percentage ("1", "1.5", 2) and returns basis points (100, 150, 200); anything else is 0.
     */
    public static function bufferBps($percent): int
    {
        if (is_string($percent)) {
            $percent = str_replace(',', '.', trim($percent));
        }
        if (!is_numeric($percent)) {
            return 0;
        }
        $bps = (int) round(((float) $percent) * 100);
        return $bps < 0 ? 0 : ($bps > self::MAX_BUFFER_BPS ? self::MAX_BUFFER_BPS : $bps);
    }

    /**
     * About what an amount is in US dollars at a rate from Client::fxQuote(), for a line shown to
     * the buyer before the checkout exists ("about $48.60"). An estimate: the checkout's own
     * `usd_amount`, fixed when it is created, is what is charged.
     *
     * @return string|null Two decimals, or null when the inputs don't make an amount.
     */
    public static function estimateUsd($amount, string $currency, $rate, int $bufferBps = 0): ?string
    {
        if (!is_numeric($amount) || !is_string($rate) || !preg_match('/^[0-9]{1,9}\.[0-9]{1,18}$/D', $rate)) {
            return null;
        }
        $usd = (float) $amount * (float) $rate * (1 + max(0, min(self::MAX_BUFFER_BPS, $bufferBps)) / 10000);
        if (!is_finite($usd) || $usd <= 0) {
            return null;
        }
        return number_format(round($usd, 2), 2, '.', '');
    }

    /**
     * One line for buyers before they are redirected, or '' for a store priced in US dollars.
     *
     * @param string|null $usdEstimate From estimateUsd(), or null when no rate is at hand.
     */
    public static function buyerNotice(string $currency, ?string $usdEstimate = null): string
    {
        if (self::USD === $currency || '' === $currency) {
            return '';
        }
        if (null !== $usdEstimate && preg_match('/^[0-9]{1,13}\.[0-9]{2}$/D', $usdEstimate)) {
            return 'You\'ll be charged in US dollars: about $' . $usdEstimate . '. You see the exact amount before you pay.';
        }
        return 'You\'ll be charged in US dollars. You see the exact amount before you pay.';
    }

    /** Labels for an "Exchange-rate buffer" setting. */
    public static function bufferLabel(): string
    {
        return 'Exchange-rate buffer (%)';
    }

    /** Help text for that setting. */
    public static function bufferHelp(): string
    {
        return 'Only for stores that don\'t price in US dollars. Buyers are charged in US dollars at the exchange rate of the moment they check out, and you are paid in USDC. '
            . 'A buffer adds a margin to the converted amount, to cover exchange-rate movement until you convert your USDC. 0 to 5. Leave it at 0 to charge the plain converted amount.';
    }
}
