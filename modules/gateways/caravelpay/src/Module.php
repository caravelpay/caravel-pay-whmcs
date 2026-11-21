<?php

namespace CaravelPay\Whmcs;

use CaravelPay\Currency;
use CaravelPay\FeeMode;
use CaravelPay\Kit\ConnectException;
use CaravelPay\Kit\Gateway;
use CaravelPay\Kit\Invoice;
use CaravelPay\Kit\PdoStorage;

/**
 * The WHMCS gateway module: settings, the "Pay now" button, the pay redirect and the webhook.
 */
final class Module
{
    const VERSION = '1.2.1';
    const GATEWAY = 'caravelpay';
    const TABLE_PREFIX = 'mod_caravelpay_';
    const PAYGATE_GATEWAYS = array('paygatedottohosted', 'paygatedottocustom');

    /**
     * Gateway settings shown under Setup > Payments > Payment Gateways.
     *
     * @return array<string, mixed>
     */
    public static function config()
    {
        return array(
            'FriendlyName' => array('Type' => 'System', 'Value' => 'Caravel Pay (card, Apple Pay, Google Pay, USDC)'),
            'payoutAddress' => array(
                'FriendlyName' => 'Payout wallet (Polygon)',
                'Type' => 'text',
                'Size' => '50',
                'Description' => self::statusHtml(),
            ),
            'contactEmail' => array(
                'FriendlyName' => 'Contact email',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Optional. For operational notices from Caravel Pay. Never used to log in.',
            ),
            'feeMode' => array(
                'FriendlyName' => 'Who pays the fees',
                'Type' => 'dropdown',
                'Options' => FeeMode::labels(),
                'Default' => FeeMode::SELLER,
                'Description' => self::e(FeeMode::help()),
            ),
            'fxBuffer' => array(
                'FriendlyName' => Currency::bufferLabel(),
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => self::e(Currency::bufferHelp()),
            ),
        );
    }

    /**
     * On save: refuse a malformed wallet and connect. Temporary Caravel Pay outages don't block saving;
     * the module then connects on the first payment.
     *
     * @param array<string, mixed> $params
     */
    public static function validate(array $params)
    {
        $wallet = isset($params['payoutAddress']) ? trim((string) $params['payoutAddress']) : '';
        if ('' === $wallet) {
            return; // Saving without a wallet (for example while deactivating) is allowed.
        }
        try {
            self::gateway($params)->ensureConnected();
        } catch (ConnectException $e) {
            if (in_array($e->getErrorCode(), array('rate_limited', 'unavailable'), true)) {
                return;
            }
            $class = '\\WHMCS\\Exception\\Module\\InvalidConfiguration';
            if (class_exists($class)) {
                throw new $class($e->getMessage());
            }
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * The invoice-page button: a signed POST to pay.php, which creates the checkout when clicked.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public static function link(array $params)
    {
        $invoiceId = isset($params['invoiceid']) ? (string) $params['invoiceid'] : '';
        // The currency WHMCS hands this gateway: the invoice's own, or the one set under Convert To For Processing.
        // US dollars are charged as they are; another supported currency is converted by Caravel Pay at checkout.
        $currency = Currency::code(isset($params['currency']) ? $params['currency'] : '');
        if (!Currency::isSupported($currency)) {
            return '<p class="caravelpay-note">' . self::e('This invoice\'s currency can\'t be paid with Caravel Pay.') . '</p>';
        }
        $ledger = new WhmcsLedger(self::pdo());
        $invoice = $ledger->invoice($invoiceId);
        if (null === $invoice || Invoice::UNPAID !== $invoice->status) {
            return '';
        }
        $amount = WhmcsLedger::money(isset($params['amount']) ? $params['amount'] : '0');
        $gateway = self::gateway($params);
        // A US dollar form is signed exactly as before; another currency is part of what is signed.
        $signature = $gateway->linkSignature(Currency::USD === $currency ? array($invoiceId, $amount, $invoice->nativeAmount) : array($invoiceId, $amount, $invoice->nativeAmount, $currency));
        $invoice->usdAmount = Currency::USD === $currency ? $amount : '';
        $notice = $gateway->buyerNotice($invoice);
        $action = self::systemUrl($params) . 'modules/gateways/caravelpay/pay.php';
        $label = isset($params['langpaynow']) && '' !== (string) $params['langpaynow'] ? (string) $params['langpaynow'] : 'Pay now';
        return '<form method="post" action="' . self::e($action) . '" class="caravelpay-form">'
            . '<input type="hidden" name="invoice" value="' . self::e($invoiceId) . '">'
            . '<input type="hidden" name="amount" value="' . self::e($amount) . '">'
            . '<input type="hidden" name="native" value="' . self::e($invoice->nativeAmount) . '">'
            . (Currency::USD === $currency ? '' : '<input type="hidden" name="cur" value="' . self::e($currency) . '">')
            . '<input type="hidden" name="sig" value="' . self::e($signature) . '">'
            . '<button type="submit" class="btn btn-success">' . self::e($label) . '</button>'
            . '<p class="caravelpay-note"><small>' . self::e('Card, Apple Pay, Google Pay or USDC. You\'ll be sent to a secure hosted checkout.') . '</small></p>'
            . ('' !== $notice ? '<p class="caravelpay-note caravelpay-currency"><small>' . self::e($notice) . '</small></p>' : '')
            . ('' !== $gateway->feeNotice() ? '<p class="caravelpay-note caravelpay-fees"><small>' . self::e($gateway->feeNotice()) . '</small></p>' : '')
            . '</form>';
    }

    /**
     * pay.php: verify the signed form, then start or resume the invoice's checkout.
     *
     * @param array<string, mixed> $post
     *
     * @return array{status: int, location?: string, html?: string}
     */
    public static function pay(array $post, string $method)
    {
        $vars = self::gatewayVariables();
        if (!$vars) {
            return self::page(404, 'This payment method is not available.', '');
        }
        $system = self::systemUrl($vars);
        $invoiceId = isset($post['invoice']) && is_string($post['invoice']) ? $post['invoice'] : '';
        $back = '' !== $invoiceId && preg_match('/^[1-9][0-9]{0,18}$/D', $invoiceId) ? $system . 'viewinvoice.php?id=' . $invoiceId : $system . 'clientarea.php';
        if ('POST' !== strtoupper($method)) {
            return array('status' => 303, 'location' => $back);
        }
        $amount = isset($post['amount']) && is_string($post['amount']) ? $post['amount'] : '';
        $native = isset($post['native']) && is_string($post['native']) ? $post['native'] : '';
        $signature = isset($post['sig']) && is_string($post['sig']) ? $post['sig'] : '';
        // Absent for a US dollar form (signed as before); otherwise the currency the invoice is charged in, signed with it.
        $currency = isset($post['cur']) && is_string($post['cur']) ? $post['cur'] : Currency::USD;
        $gateway = self::gateway($vars);
        if (!Currency::isSupported($currency) || !$gateway->checkLinkSignature(Currency::USD === $currency ? array($invoiceId, $amount, $native) : array($invoiceId, $amount, $native, $currency), $signature)) {
            return self::page(400, 'This payment link is not valid. Please open the invoice again.', $back);
        }
        $ledger = new WhmcsLedger(self::pdo());
        $invoice = $ledger->invoice($invoiceId);
        if (null === $invoice) {
            return self::page(404, 'This invoice was not found.', $back);
        }
        if (Invoice::UNPAID === $invoice->status && $invoice->nativeAmount !== $native) {
            // The balance changed since the page was shown (a partial payment, an edit): show the fresh amount.
            return array('status' => 303, 'location' => $back);
        }
        // US dollars: the amount WHMCS handed over (the invoice's own, or its conversion). Another currency: the invoice
        // goes out in its own currency and Caravel Pay converts it when it creates the checkout.
        $invoice->usdAmount = Currency::USD === $currency ? $amount : '';
        $result = $gateway->startPayment($invoice, $back . '&paymentsuccess=true', $back . '&paymentfailed=true');
        if (isset($result['redirect'])) {
            return array('status' => 303, 'location' => $result['redirect']);
        }
        return self::page('busy' === $result['error'] ? 409 : 200, $result['message'], $back);
    }

    /**
     * The webhook endpoint (callback/caravelpay.php).
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function callback(string $body, array $headers, string $requestUri, string $method)
    {
        if ('POST' !== strtoupper($method)) {
            return array('status' => 405, 'body' => array('error' => 'method_not_allowed'));
        }
        $vars = self::gatewayVariables();
        if (!$vars) {
            return array('status' => 503, 'body' => array('error' => 'not_activated'));
        }
        $urls = array(self::systemUrl($vars) . 'modules/gateways/callback/caravelpay.php');
        if ('' !== $requestUri && '/' === $requestUri[0]) {
            $urls[] = $requestUri; // The path that was actually requested, in case the system URL changed.
        }
        $result = self::gateway($vars)->handleWebhook($body, $headers, $urls);
        return array('status' => $result['status'], 'body' => $result['body']);
    }

    /**
     * The kit gateway for these settings.
     *
     * @param array<string, mixed> $params Gateway parameters (payoutAddress, contactEmail, systemurl).
     */
    public static function gateway(array $params): Gateway
    {
        $system = self::systemUrl($params);
        $config = array(
            'platform' => 'whmcs',
            'platform_name' => 'WHMCS',
            'version' => self::VERSION,
            'payout_address' => isset($params['payoutAddress']) ? trim((string) $params['payoutAddress']) : '',
            'email' => isset($params['contactEmail']) ? trim((string) $params['contactEmail']) : '',
            // Who pays the fees: the saved dropdown value; anything else (or nothing saved yet) is the default.
            'fee_mode' => isset($params['feeMode']) ? (string) $params['feeMode'] : FeeMode::SELLER,
            'fx_buffer' => isset($params['fxBuffer']) ? (string) $params['fxBuffer'] : '0',
            'site_url' => $system,
            'callback_url' => $system . 'modules/gateways/callback/caravelpay.php',
        );
        if (defined('CARAVEL_PAY_API_BASE')) {
            $config['api_base'] = (string) constant('CARAVEL_PAY_API_BASE');
        }
        if (defined('CARAVEL_PAY_TESTING')) {
            foreach (array('caravel_transport' => 'transport', 'caravel_clock' => 'clock') as $from => $to) {
                if (isset($GLOBALS[$from])) {
                    $config[$to] = $GLOBALS[$from];
                }
            }
        }
        $encrypt = function_exists('encrypt') ? 'encrypt' : null;
        $decrypt = function_exists('decrypt') ? 'decrypt' : null;
        return new Gateway(new PdoStorage(self::pdo(), self::TABLE_PREFIX, $encrypt, $decrypt), new WhmcsLedger(self::pdo()), $config);
    }

    /**
     * The saved wallet of an installed PayGate gateway, read-only, so the admin can paste it.
     */
    public static function paygateWallet(): string
    {
        try {
            $pdo = self::pdo();
            $statement = $pdo->prepare('SELECT value FROM tblpaymentgateways WHERE gateway IN (?, ?)');
            if (false === $statement || !$statement->execute(self::PAYGATE_GATEWAYS)) {
                return '';
            }
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $value) {
                if (is_string($value) && preg_match('/^\s*(0x[0-9a-fA-F]{40})\s*$/D', $value, $match)) {
                    return $match[1];
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /** @return array<string, mixed> Gateway variables, or [] when the module isn't activated. */
    private static function gatewayVariables()
    {
        $vars = getGatewayVariables(self::GATEWAY);
        return is_array($vars) && !empty($vars['type']) ? $vars : array();
    }

    /** The WHMCS System URL with a trailing slash. */
    public static function systemUrl(array $params): string
    {
        $url = isset($params['systemurl']) ? (string) $params['systemurl'] : '';
        if ('' === $url && class_exists('\\WHMCS\\Config\\Setting')) {
            $url = (string) call_user_func(array('\\WHMCS\\Config\\Setting', 'getValue'), 'SystemURL');
        }
        return '' === $url ? '' : rtrim($url, '/') . '/';
    }

    private static function pdo(): \PDO
    {
        if (defined('CARAVEL_PAY_TESTING') && isset($GLOBALS['caravel_pdo']) && $GLOBALS['caravel_pdo'] instanceof \PDO) {
            return $GLOBALS['caravel_pdo'];
        }
        return call_user_func(array('\\WHMCS\\Database\\Capsule', 'connection'))->getPdo();
    }

    /** Connection status for the settings page. Never shows the secret. */
    private static function statusHtml(): string
    {
        $lines = array('Your Polygon address (0x…). Every payout is sent here, minus the 2% Caravel fee. Saving connects WHMCS to Caravel Pay; a new wallet gets new API keys.');
        try {
            $vars = function_exists('getGatewayVariables') ? getGatewayVariables(self::GATEWAY) : array();
            $summary = self::gateway(is_array($vars) ? $vars : array('systemurl' => ''))->summary();
            if ($summary['connected']) {
                $lines[] = '<strong>Connected</strong> as ' . self::e($summary['merchant_id']) . ' for ' . self::e($summary['payout_address'])
                    . ($summary['matches'] ? '.' : '. The wallet or System URL changed: the module connects again on the next save or payment.');
            } else {
                $lines[] = '<strong>Not connected yet.</strong>';
            }
            $paygate = self::paygateWallet();
            if ('' !== $paygate) {
                $lines[] = 'Your PayGate gateway pays out to ' . self::e($paygate) . '. Paste it above to keep the same wallet.';
            }
        } catch (\Throwable $e) {
            $lines[] = 'Connection status unavailable.';
        }
        $lines[] = 'Invoices in US dollars are charged as they are. Invoices in EUR, GBP and other supported currencies are converted to US dollars when the client pays. For any other currency, set Convert To For Processing to USD.';
        return implode('<br>', $lines);
    }

    private static function page(int $status, string $message, string $back): array
    {
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment</title>'
            . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem">'
            . '<p>' . self::e($message) . '</p>'
            . ('' !== $back ? '<p><a href="' . self::e($back) . '">' . self::e('Back to the invoice') . '</a></p>' : '')
            . '</div>';
        return array('status' => $status, 'html' => $html);
    }

    private static function e($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
