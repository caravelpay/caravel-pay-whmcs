<?php
/**
 * Starts (or resumes) the Caravel Pay checkout for an invoice and redirects the buyer to it.
 * Reached only from the signed "Pay now" form that caravelpay_link() renders.
 */
require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/bootstrap.php';

$result = \CaravelPay\Whmcs\Module::pay($_POST, isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '');
header('Cache-Control: no-store');
if (isset($result['location'])) {
    header('Location: ' . $result['location'], true, $result['status']);
    exit;
}
http_response_code($result['status']);
header('Content-Type: text/html; charset=utf-8');
echo $result['html'];
