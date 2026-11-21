<?php
/**
 * Caravel Pay webhook endpoint: modules/gateways/callback/caravelpay.php
 *
 * Verifies the signature, then re-fetches the checkout from the signed API before changing the
 * invoice. Only a confirmed `paid` checkout adds a payment (addInvoicePayment with the payout
 * transaction hash). Every outcome is written to Billing > Gateway Log.
 */
require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/../caravelpay/bootstrap.php';

$result = \CaravelPay\Whmcs\Module::callback(
    \CaravelPay\Webhook::readBody(),
    \CaravelPay\Webhook::headersFromServer($_SERVER),
    isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
    isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : ''
);
http_response_code($result['status']);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode($result['body']);
