<?php
/**
 * Caravel Pay for WHMCS: card, Apple Pay, Google Pay and USDC payments, paid out to your own
 * Polygon wallet, minus the 2% Caravel fee.
 *
 * Install: upload modules/ into your WHMCS root, then activate "Caravel Pay" under
 * Setup > Payments > Payment Gateways and paste your payout wallet.
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/caravelpay/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function caravelpay_MetaData()
{
    return array(
        'DisplayName' => 'Caravel Pay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * @return array<string, mixed>
 */
function caravelpay_config()
{
    return \CaravelPay\Whmcs\Module::config();
}

/**
 * Runs when the settings are saved (WHMCS versions that support configuration validation):
 * checks the wallet and connects to Caravel Pay. Older versions connect on the first payment.
 *
 * @param array<string, mixed> $params
 */
function caravelpay_config_validate(array $params)
{
    \CaravelPay\Whmcs\Module::validate($params);
}

/**
 * The "Pay now" button on the invoice.
 *
 * @param array<string, mixed> $params
 *
 * @return string
 */
function caravelpay_link($params)
{
    return \CaravelPay\Whmcs\Module::link($params);
}
