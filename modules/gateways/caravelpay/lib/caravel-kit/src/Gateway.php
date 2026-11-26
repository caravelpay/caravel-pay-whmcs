<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

use CaravelPay\Amount;
use CaravelPay\Checkout;
use CaravelPay\Client;
use CaravelPay\Currency;
use CaravelPay\Exception\ApiException;
use CaravelPay\Exception\CaravelPayException;
use CaravelPay\Exception\SignatureVerificationException;
use CaravelPay\FeeMode;
use CaravelPay\Webhook;

/**
 * The invoice payment flow shared by the billing-platform integrations (WHMCS, FOSSBilling,
 * Paymenter, Easy Digital Downloads…), built on the Caravel Pay PHP SDK:
 *
 * - ensureConnected(): POST /v1/connect when there are no credentials for the configured wallet and
 *   site (first use, wallet change). Replaced credentials are kept 7 days for in-flight webhooks.
 * - startPayment(): invoice → checkout → redirect. The request is saved before the network call, an
 *   open checkout is reused, and a new order_id is used only after the old checkout is confirmed expired.
 * - handleWebhook(): verify → skip duplicates → re-fetch the checkout with the merchant that created
 *   it → apply. Only a re-fetched `paid` records a payment; underpaid/review are flagged, never paid.
 */
final class Gateway
{
    public const KEEP_RETIRED_SECONDS = 604800;
    public const EXPIRY_GRACE_SECONDS = 60;
    public const EVENT_MEMORY_SECONDS = 2592000;
    public const LOCK_SECONDS = 30;
    private const WALLET = '/^0x[0-9a-fA-F]{40}$/D';

    /** @var Storage */
    private $storage;
    /** @var Ledger */
    private $ledger;
    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $config platform (slug, e.g. "whmcs"), platform_name (e.g. "WHMCS"), version,
     *        payout_address, email, site_url, callback_url, order_prefix (default "<platform>-"),
     *        fee_mode (who pays the fees: "seller", the default, "split" or "buyer"; see CaravelPay\FeeMode),
     *        fx_buffer (a percentage, "0" to "5": the margin added to the converted amount of an invoice in another
     *        currency; default 0; see CaravelPay\Currency),
     *        and optionally api_base, transport, clock (callable returning unix seconds), timeout.
     */
    public function __construct(Storage $storage, Ledger $ledger, array $config)
    {
        foreach (['platform', 'version', 'site_url', 'callback_url'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || '' === $config[$key]) {
                throw new \InvalidArgumentException('Missing gateway setting ' . $key . '.');
            }
        }
        if (!preg_match('/^[a-z][a-z0-9]{1,20}$/D', $config['platform'])) {
            throw new \InvalidArgumentException('Invalid platform slug.');
        }
        $config += [
            'platform_name' => ucfirst($config['platform']),
            'payout_address' => '',
            'email' => '',
            'order_prefix' => $config['platform'] . '-',
            'clock' => 'time',
            'fee_mode' => FeeMode::SELLER,
            'fx_buffer' => '0',
        ];
        // A saved setting: anything that isn't split or buyer is the default.
        $config['fee_mode'] = FeeMode::normalize($config['fee_mode']);
        $this->storage = $storage;
        $this->ledger = $ledger;
        $this->config = $config;
    }

    public function now(): int
    {
        return (int) call_user_func($this->config['clock']);
    }

    // ------------------------------------------------------------------ connection

    /** The current credentials row (with its secret), or null. */
    public function current(): ?array
    {
        foreach ($this->storage->credentials() as $row) {
            if ('current' === $row['status']) {
                return $row;
            }
        }
        return null;
    }

    /**
     * For the settings page: whether credentials exist and match the saved wallet and site. No secret.
     *
     * @return array{connected: bool, merchant_id: string, payout_address: string, matches: bool}
     */
    public function summary(): array
    {
        $current = $this->current();
        if (null === $current) {
            return ['connected' => false, 'merchant_id' => '', 'payout_address' => '', 'matches' => false];
        }
        return [
            'connected' => true,
            'merchant_id' => (string) $current['merchant_id'],
            'payout_address' => (string) $current['payout_address'],
            'matches' => $this->matches($current),
        ];
    }

    /**
     * Credentials for the configured wallet and site, connecting first when there are none.
     *
     * @throws ConnectException
     */
    public function ensureConnected(bool $force = false): array
    {
        $wallet = trim((string) $this->config['payout_address']);
        if (!preg_match(self::WALLET, $wallet) || preg_match('/^0x0{40}$/iD', $wallet)) {
            throw new ConnectException('invalid_wallet', 'Enter your Polygon payout wallet: 0x followed by 40 hexadecimal characters.');
        }
        if (0 !== stripos((string) $this->config['site_url'], 'https://')) {
            throw new ConnectException('invalid_url', 'Your site address must start with https:// (Caravel Pay returns buyers and sends webhooks to it).');
        }
        $current = $this->current();
        if (null !== $current && !$force && $this->matches($current)) {
            return $current;
        }
        $now = $this->now();
        $retryAt = (int) $this->storage->setting('connect_retry_at');
        if ($retryAt > $now) {
            throw new ConnectException('rate_limited', 'Connecting to Caravel Pay is paused for ' . ($retryAt - $now) . ' more seconds. Please try again then.');
        }
        $lock = $this->storage->lock('connect', self::LOCK_SECONDS, $now);
        if (null === $lock) {
            throw new ConnectException('unavailable', 'Another request is connecting to Caravel Pay right now. Please try again in a moment.');
        }
        try {
            $current = $this->current();
            if (null !== $current && !$force && $this->matches($current)) {
                return $current;
            }
            $params = [
                'site_url' => (string) $this->config['site_url'],
                'payout_address' => $wallet,
                'platform' => 'api',
                'plugin_version' => substr($this->config['platform'] . '-' . $this->config['version'], 0, 32),
            ];
            $email = trim((string) $this->config['email']);
            if ('' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $params['email'] = $email;
            }
            try {
                $result = Client::connect($params, $this->clientOptions());
            } catch (ApiException $e) {
                throw $this->connectFailure($e, $now);
            } catch (CaravelPayException $e) {
                $this->storage->saveSetting('connect_retry_at', (string) ($now + 30));
                $this->ledger->log('error', 'Connecting to Caravel Pay failed: ' . get_class($e) . '.');
                throw new ConnectException('unavailable', 'Caravel Pay could not be reached. Please try again in a minute.');
            }
            if (null !== $current) {
                $this->storage->retireCredentials((string) $current['merchant_id'], $now);
            }
            $this->storage->addCredentials([
                'merchant_id' => $result['merchant_id'],
                'secret' => $result['secret'],
                'payout_address' => $wallet,
                'site_url' => (string) $this->config['site_url'],
                'status' => 'current',
                'created_at' => $now,
            ]);
            $this->purge($now);
            $this->ledger->log('pending_review' === $result['status'] ? 'warning' : 'info', 'Connected to Caravel Pay as ' . $result['merchant_id'] . ' (status ' . $result['status'] . ', fee ' . $result['fee_rate'] . ')'
                . (null !== $current ? '; replaced ' . $current['merchant_id'] . ', kept for open checkouts.' : '.'));
            $connected = $this->current();
            if (null === $connected) {
                throw new ConnectException('error', 'The new Caravel Pay credentials could not be saved.');
            }
            return $connected;
        } finally {
            $this->storage->unlock('connect', $lock);
        }
    }

    /**
     * GET /v1/merchant with the current credentials (settings page "test connection").
     *
     * @throws CaravelPayException|ConnectException
     */
    public function merchant(): array
    {
        $current = $this->current();
        if (null === $current) {
            throw new ConnectException('error', 'Not connected yet.');
        }
        return $this->client($current)->getMerchant();
    }

    // ------------------------------------------------------------------ pay links

    /** HMAC of the parts with a random key kept in storage: binds a pay link to its invoice and amounts. */
    public function linkSignature(array $parts): string
    {
        $key = $this->storage->setting('link_key');
        if (null === $key || 64 !== strlen($key)) {
            $key = bin2hex(random_bytes(32));
            $this->storage->saveSetting('link_key', $key);
        }
        return hash_hmac('sha256', implode("\n", array_map('strval', $parts)), $key);
    }

    public function checkLinkSignature(array $parts, string $signature): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/D', $signature) && hash_equals($this->linkSignature($parts), $signature);
    }

    // ------------------------------------------------------------------ payments

    /**
     * Start (or resume) paying an invoice. Returns ['redirect' => url] or ['error' => code, 'message' => text for the buyer].
     */
    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): array
    {
        if (Invoice::UNPAID !== $invoice->status) {
            return $this->refuse('not_payable', 'This invoice can no longer be paid.');
        }
        // What is charged: the platform's own US dollar figure when it has one; otherwise the invoice in its own
        // currency, which Caravel Pay converts to US dollars when it creates the checkout.
        $currency = $this->chargeCurrency($invoice);
        if (null === $currency) {
            return $this->refuse('currency', 'This invoice\'s currency can\'t be paid with Caravel Pay.');
        }
        try {
            $amount = Currency::format(Currency::USD === $currency ? $invoice->usdAmount : $invoice->nativeAmount, $currency);
            $native = Amount::normalize($invoice->nativeAmount);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse('amount', 'This invoice amount can\'t be paid with Caravel Pay.');
        }
        if ('' === trim($amount, '0.') || (Currency::USD === $currency && Amount::compare($amount, '1000000.00') > 0)) {
            return $this->refuse('amount', 'This invoice amount can\'t be paid with Caravel Pay.');
        }
        try {
            $credentials = $this->ensureConnected();
        } catch (ConnectException $e) {
            $this->ledger->log('error', 'Invoice ' . $invoice->ref . ': Caravel Pay is not connected (' . $e->getErrorCode() . '): ' . $e->getMessage());
            return $this->unavailable();
        }
        $now = $this->now();
        $lock = $this->storage->lock('invoice:' . $invoice->ref, self::LOCK_SECONDS, $now);
        if (null === $lock) {
            return $this->refuse('busy', 'A payment for this invoice is being started. Please try again in a moment.');
        }
        try {
            return $this->startLocked($invoice, $credentials, $amount, $native, $returnUrl, $cancelUrl, $now, $currency);
        } finally {
            $this->storage->unlock('invoice:' . $invoice->ref, $lock);
        }
    }

    /**
     * The currency an invoice is charged in: "USD" when the platform has a US dollar amount for it, the invoice's own
     * currency when Caravel Pay can convert it, or null when it can't be paid here.
     */
    public function chargeCurrency(Invoice $invoice): ?string
    {
        if ('' !== (string) $invoice->usdAmount) {
            return Currency::USD;
        }
        $currency = Currency::code($invoice->nativeCurrency);
        return Currency::isSupported($currency) && Currency::USD !== $currency ? $currency : null;
    }

    /**
     * The line to show a buyer before they are sent to the checkout: '' when the invoice is charged as it stands
     * (US dollars), otherwise "You'll be charged in US dollars: about $48.60. …". The rate is cached for ten minutes.
     */
    public function buyerNotice(Invoice $invoice): string
    {
        $currency = $this->chargeCurrency($invoice);
        if (null === $currency || Currency::USD === $currency) {
            return '';
        }
        return Currency::buyerNotice($currency, Currency::estimateUsd($invoice->nativeAmount, $currency, $this->fxRate($currency), Currency::bufferBps($this->config['fx_buffer'])));
    }

    /** US dollars for one unit of a currency, from Caravel Pay, cached for ten minutes; '' when none is at hand. */
    private function fxRate(string $currency): string
    {
        $now = $this->now();
        $key = 'fx_rate_' . strtolower($currency);
        $cached = json_decode((string) $this->storage->setting($key), true);
        if (is_array($cached) && isset($cached['rate'], $cached['until']) && is_string($cached['rate']) && (int) $cached['until'] > $now) {
            return $cached['rate'];
        }
        $rate = '';
        // Only with credentials already in hand: looking at an invoice never connects the store.
        $credentials = $this->current();
        if (null !== $credentials && isset($credentials['merchant_id'], $credentials['secret'])) {
            try {
                $quote = $this->client($credentials)->fxQuote($currency);
                $rate = (string) $quote['fx_rate'];
            } catch (\Throwable $e) {
                $rate = ''; // No rate right now: the line is shown without an estimate.
            }
        }
        // A missing rate is remembered for two minutes, so an invoice page never waits for it on every load.
        $this->storage->saveSetting($key, (string) json_encode(['rate' => $rate, 'until' => $now + ('' === $rate ? 120 : 600)]));
        return $rate;
    }

    private function startLocked(Invoice $invoice, array $credentials, string $amount, string $native, string $returnUrl, string $cancelUrl, int $now, string $currency = Currency::USD): array
    {
        $state = $this->storage->invoiceState($invoice->ref);
        if (null === $state) {
            $state = ['invoice_ref' => $invoice->ref, 'salt' => self::salt(), 'attempt' => 1, 'current_checkout' => '', 'pending' => null, 'updated_at' => $now];
        }
        if ('' !== $state['current_checkout']) {
            $row = $this->storage->checkout($state['current_checkout']);
            if (null !== $row && $row['amount'] === $amount && $row['native_amount'] === $native && $row['merchant_id'] === $credentials['merchant_id']) {
                if ($row['expires_at'] + self::EXPIRY_GRACE_SECONDS > $now && '' !== $row['checkout_url']) {
                    return ['redirect' => $row['checkout_url']];
                }
                // Past its expiry: confirm before starting a new checkout, so a payment in flight is never doubled.
                $outcome = $this->refreshAndApply($row);
                $status = $outcome['checkout_status'];
                if ('' === $status) {
                    return $this->unavailable();
                }
                if ('awaiting_payment' === $status) {
                    return ['redirect' => $row['checkout_url']];
                }
                if ('paid' === $status) {
                    return $this->refuse('paid', 'This invoice has been paid. Thank you!');
                }
                if ('expired' !== $status) {
                    return $this->refuse('in_progress', 'A payment for this invoice is already in progress. Please wait for it to finish, or contact us.');
                }
            }
            // Expired, or the amount or merchant changed: the next attempt gets a new order_id.
            $state['attempt'] = (int) $state['attempt'] + 1;
            $state['current_checkout'] = '';
            $state['pending'] = null;
        }
        $callback = (string) $this->config['callback_url'];
        $pending = $state['pending'];
        if (is_array($pending) && ($pending['amount'] !== $amount || (isset($pending['body']['currency']) ? $pending['body']['currency'] : Currency::USD) !== $currency
            || $pending['native_amount'] !== $native || $pending['merchant_id'] !== $credentials['merchant_id']
            || $pending['body']['return_url'] !== $returnUrl || $pending['body']['cancel_url'] !== $cancelUrl || $pending['body']['callback_url'] !== $callback)) {
            // An unanswered request for different details may still hold its order_id: use a new one.
            $state['attempt'] = (int) $state['attempt'] + 1;
            $pending = null;
        }
        for ($tries = 0; $tries < 2; $tries++) {
            if (!is_array($pending)) {
                $body = ['order_id' => $this->orderId($invoice->ref, (string) $state['salt'], (int) $state['attempt']), 'amount' => $amount, 'currency' => $currency];
                if (null !== $invoice->email && false !== filter_var($invoice->email, FILTER_VALIDATE_EMAIL) && strlen($invoice->email) <= 254) {
                    $body['customer_email'] = $invoice->email;
                }
                $body += ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'callback_url' => $callback];
                // Who pays the fees: nothing is added for the default, so a default installation sends the same request as before.
                $body = FeeMode::apply($body, $this->config['fee_mode']);
                // Another currency may carry the store's margin on the converted amount (default 0: nothing is added).
                $buffer = Currency::USD === $currency ? 0 : Currency::bufferBps($this->config['fx_buffer']);
                if ($buffer > 0) {
                    $body['fx_buffer_bps'] = $buffer;
                }
                $pending = ['body' => $body, 'merchant_id' => $credentials['merchant_id'], 'amount' => $amount, 'native_amount' => $native, 'native_currency' => $invoice->nativeCurrency];
            }
            $state['pending'] = $pending;
            $state['updated_at'] = $now;
            $this->storage->saveInvoiceState($state); // Saved before a possibly ambiguous network call.
            try {
                $checkout = $this->client($credentials)->createCheckout($pending['body']);
            } catch (ApiException $e) {
                $this->ledger->log($e->isRetryable() ? 'warning' : 'error', 'Invoice ' . $invoice->ref . ': creating the checkout failed: ' . $e->getErrorCode()
                    . (null !== $e->getParam() ? ' (' . $e->getParam() . ')' : '') . ', HTTP ' . $e->getHttpStatus() . '.');
                if ($e->isRetryable()) {
                    return $this->unavailable(); // Same order_id next time: no duplicate checkout.
                }
                $state['pending'] = null;
                if ('order_id_conflict' === $e->getErrorCode() && 0 === $tries) {
                    $state['attempt'] = (int) $state['attempt'] + 1;
                    $pending = null;
                    continue;
                }
                $state['attempt'] = (int) $state['attempt'] + 1;
                $this->storage->saveInvoiceState($state);
                return $this->unavailable();
            } catch (CaravelPayException $e) {
                $this->ledger->log('warning', 'Invoice ' . $invoice->ref . ': Caravel Pay could not be reached (' . get_class($e) . '); the same request is retried next time.');
                return $this->unavailable();
            }
            $url = (string) $checkout['checkout_url'];
            if (!$this->safeRedirect($url)) {
                $this->ledger->log('error', 'Invoice ' . $invoice->ref . ': the checkout URL was not HTTPS; refused.');
                return $this->unavailable();
            }
            $expires = isset($checkout['expires_at']) ? strtotime((string) $checkout['expires_at']) : false;
            $existing = $this->storage->checkout((string) $checkout['id']);
            $this->storage->saveCheckout([
                'checkout_id' => (string) $checkout['id'],
                'invoice_ref' => $invoice->ref,
                'merchant_id' => $credentials['merchant_id'],
                'order_id' => $pending['body']['order_id'],
                'amount' => $amount,
                'native_amount' => $native,
                'native_currency' => (string) $pending['native_currency'],
                'checkout_url' => $url,
                'expires_at' => false === $expires ? $now + 3600 : $expires,
                'status' => null !== $existing ? $existing['status'] : (string) $checkout['status'],
                'paid_amount' => null !== $existing ? $existing['paid_amount'] : '0.00',
                'applied' => null !== $existing ? $existing['applied'] : 0,
                'created_at' => null !== $existing ? $existing['created_at'] : $now,
                'updated_at' => $now,
            ]);
            $state['current_checkout'] = (string) $checkout['id'];
            $state['pending'] = null;
            $this->storage->saveInvoiceState($state);
            $this->ledger->log('info', 'Invoice ' . $invoice->ref . ': checkout ' . $checkout['id'] . (null === $existing ? ' created' : ' resumed') . ' (order_id ' . $pending['body']['order_id'] . ', ' . (Currency::USD === $currency ? '$' . $amount : $amount . ' ' . $currency . ' = $' . Checkout::usdAmount($checkout)) . ').');
            return ['redirect' => $url];
        }
        return $this->unavailable();
    }

    // ------------------------------------------------------------------ webhooks

    /**
     * Handle a webhook request. Returns ['status' => HTTP status, 'body' => array, 'result' => string].
     *
     * @param array<string, mixed> $headers      Request headers (any case or $_SERVER style).
     * @param string[]             $callbackUrls The callback URL(s) this endpoint is reachable at (signed path).
     */
    public function handleWebhook(string $body, array $headers, array $callbackUrls): array
    {
        $now = $this->now();
        $secrets = $this->secrets($now);
        if (!$secrets) {
            $this->ledger->log('warning', 'Webhook received while not connected; answered 503 so it is retried.');
            return $this->reply(503, 'not_connected');
        }
        try {
            $event = Webhook::verify($body, $headers, $secrets, $callbackUrls, ['now' => $now]);
        } catch (SignatureVerificationException $e) {
            $this->ledger->log('warning', 'Webhook rejected: ' . $e->getReason() . '.');
            return $this->reply($e->getHttpStatus(), $e->getReason());
        }
        $label = 'Webhook ' . $event->id . ' (' . $event->type . ', ' . $event->checkoutId() . ')';
        if (!$event->isKnownType()) {
            $this->ledger->log('info', $label . ': event type not handled by this version; acknowledged.');
            return $this->reply(200, 'ignored');
        }
        if ($this->storage->eventSeen($event->id)) {
            return $this->reply(200, 'duplicate');
        }
        $row = $this->storage->checkout($event->checkoutId());
        if (null === $row) {
            $this->ledger->log('warning', $label . ': no invoice uses this checkout; acknowledged without changes.');
            $this->storage->rememberEvent($event->id, $now);
            return $this->reply(200, 'ignored');
        }
        if (!hash_equals((string) $row['merchant_id'], $event->merchantId)) {
            $this->ledger->log('warning', $label . ': signed by ' . $event->merchantId . ', but the checkout belongs to ' . $row['merchant_id'] . '; ignored.');
            return $this->reply(409, 'merchant_mismatch');
        }
        $lock = $this->storage->lock('invoice:' . $row['invoice_ref'], self::LOCK_SECONDS, $now);
        if (null === $lock) {
            return $this->reply(503, 'busy');
        }
        try {
            if ($this->storage->eventSeen($event->id)) {
                return $this->reply(200, 'duplicate');
            }
            $outcome = $this->refreshAndApply($row);
            if ($outcome['status'] >= 200 && $outcome['status'] < 300) {
                $this->storage->rememberEvent($event->id, $now);
                if (0 === random_int(0, 49)) {
                    $this->storage->forgetEventsBefore($now - self::EVENT_MEMORY_SECONDS);
                }
            }
            $this->ledger->log($outcome['status'] < 300 ? 'info' : 'warning', $label . ': invoice ' . $row['invoice_ref'] . ' ' . $outcome['result'] . '.');
            return $this->reply($outcome['status'], $outcome['result']);
        } catch (\Throwable $e) {
            $this->ledger->log('error', $label . ': processing failed (' . get_class($e) . '); answered 503 so it is retried.');
            return $this->reply(503, 'processing_failed');
        } finally {
            $this->storage->unlock('invoice:' . $row['invoice_ref'], $lock);
        }
    }

    /**
     * Re-fetch an invoice's current checkout and apply it (return page, cron). Signed API only.
     *
     * @return array{status: int, result: string, checkout_status: string}
     */
    public function reconcile(string $invoiceRef): array
    {
        $state = $this->storage->invoiceState($invoiceRef);
        $row = null !== $state && '' !== $state['current_checkout'] ? $this->storage->checkout($state['current_checkout']) : null;
        if (null === $row) {
            return ['status' => 200, 'result' => 'no_checkout', 'checkout_status' => ''];
        }
        $lock = $this->storage->lock('invoice:' . $invoiceRef, self::LOCK_SECONDS, $this->now());
        if (null === $lock) {
            return ['status' => 503, 'result' => 'busy', 'checkout_status' => ''];
        }
        try {
            return $this->refreshAndApply($row);
        } finally {
            $this->storage->unlock('invoice:' . $invoiceRef, $lock);
        }
    }

    /**
     * @return array{status: int, result: string, checkout_status: string}
     */
    private function refreshAndApply(array $row): array
    {
        $credentials = $this->credentialsFor((string) $row['merchant_id']);
        if (null === $credentials) {
            $this->ledger->log('error', 'Checkout ' . $row['checkout_id'] . ': the credentials of ' . $row['merchant_id'] . ' are gone; it can\'t be re-fetched.');
            return ['status' => 503, 'result' => 'credentials_missing', 'checkout_status' => ''];
        }
        try {
            $checkout = $this->client($credentials)->getCheckout((string) $row['checkout_id']);
        } catch (CaravelPayException $e) {
            $code = $e instanceof ApiException ? $e->getErrorCode() : get_class($e);
            $this->ledger->log('warning', 'Checkout ' . $row['checkout_id'] . ': re-fetching failed (' . $code . ').');
            return ['status' => 503, 'result' => 'verification_pending', 'checkout_status' => ''];
        }
        // The checkout must be the one this store created: its amount, in US dollars or in the invoice's own currency
        // (whichever was sent). Amounts in different currencies are never compared.
        if ((isset($checkout['order_id']) && $checkout['order_id'] !== $row['order_id']) || $checkout['amount'] !== $row['amount']
            || !in_array($checkout['currency'], [Currency::USD, Currency::code((string) $row['native_currency'])], true)) {
            $this->ledger->log('error', 'Checkout ' . $row['checkout_id'] . ': order_id or amount differs from what this store created; ignored.');
            return ['status' => 409, 'result' => 'mismatch', 'checkout_status' => (string) $checkout['status']];
        }
        $outcome = $this->apply($row, $checkout);
        $outcome['checkout_status'] = (string) $checkout['status'];
        return $outcome;
    }

    /**
     * @return array{status: int, result: string}
     */
    private function apply(array $row, array $checkout): array
    {
        $invoice = $this->ledger->invoice((string) $row['invoice_ref']);
        $status = (string) $checkout['status'];
        $paid = isset($checkout['paid_amount']) ? (string) $checkout['paid_amount'] : '0.00';
        $changed = $row['status'] !== $status || $row['paid_amount'] !== $paid;
        $row['status'] = $status;
        $row['paid_amount'] = $paid;
        $row['updated_at'] = $this->now();
        if (null === $invoice) {
            $this->storage->saveCheckout($row);
            $this->ledger->log('warning', 'Checkout ' . $row['checkout_id'] . ' is ' . $status . ', but invoice ' . $row['invoice_ref'] . ' no longer exists.');
            return ['status' => 200, 'result' => 'no_invoice'];
        }
        $id = (string) $checkout['id'];
        switch ($status) {
            case Checkout::PAID:
                if (1 === (int) $row['applied']) {
                    $this->storage->saveCheckout($row);
                    return ['status' => 200, 'result' => 'already recorded'];
                }
                $note = $this->payoutNote($checkout);
                if (Invoice::PAID === $invoice->status) {
                    if (0 === (int) $row['applied']) {
                        $this->ledger->flag($invoice, 'paid_twice', 'Caravel Pay checkout ' . $id . ' was paid, but this invoice was already paid. Check for a double payment. ' . $note);
                    }
                    $row['applied'] = 2;
                    $this->storage->saveCheckout($row);
                    return ['status' => 200, 'result' => 'already paid; flagged'];
                }
                if (Invoice::UNPAID !== $invoice->status) {
                    if ($changed || 0 === (int) $row['applied']) {
                        $this->ledger->flag($invoice, 'paid_not_open', 'Caravel Pay checkout ' . $id . ' was paid while the invoice is "' . $invoice->platformStatus . '". It was not marked paid; please review. ' . $note);
                    }
                    $row['applied'] = 2;
                    $this->storage->saveCheckout($row);
                    return ['status' => 200, 'result' => 'not open; flagged'];
                }
                $tx = Checkout::txHash($checkout);
                // The fee you paid: the 2% Caravel fee, or nothing when the buyer paid it (fee mode "buyer").
                $fee = FeeMode::BUYER === Checkout::feeMode($checkout) ? '0.00' : self::fee((string) $row['native_amount']);
                if (!$this->ledger->recordPayment($invoice, $checkout, null !== $tx ? $tx : $id, (string) $row['native_amount'], $fee)) {
                    $this->storage->saveCheckout($row);
                    return ['status' => 503, 'result' => 'could not be recorded'];
                }
                $row['applied'] = 1;
                $this->storage->saveCheckout($row);
                return ['status' => 200, 'result' => 'paid'];
            case Checkout::UNDERPAID:
                if ($changed) {
                    $this->ledger->flag($invoice, 'underpaid', 'Caravel Pay: underpaid. $' . $paid . ' of $' . (null !== Checkout::usdAmount($checkout) ? Checkout::usdAmount($checkout) : $row['amount']) . ' arrived (checkout ' . $id . '). The invoice stays unpaid: the buyer can top up the same checkout before it expires, or contact them to settle the difference.');
                }
                break;
            case Checkout::REVIEW:
                if ($changed) {
                    $this->ledger->flag($invoice, 'review', 'Caravel Pay: the payment for checkout ' . $id . ' needs a manual review (for example a payment after expiry). The invoice stays unpaid; a follow-up webhook arrives if it is resolved.');
                }
                break;
            case Checkout::EXPIRED:
                if ($changed) {
                    $this->ledger->flag($invoice, 'expired', 'Caravel Pay checkout ' . $id . ' expired without payment. The buyer can start a new payment from the invoice.');
                }
                break;
            case Checkout::CONFIRMING:
                if ($changed) {
                    $this->ledger->log('info', 'Invoice ' . $row['invoice_ref'] . ': a payment for checkout ' . $id . ' was detected and is confirming. Don\'t deliver yet.');
                }
                break;
        }
        $this->storage->saveCheckout($row);
        return ['status' => 200, 'result' => $status];
    }

    // ------------------------------------------------------------------ helpers

    /** Who pays the fees for new checkouts: "seller", "split" or "buyer". */
    public function feeMode(): string
    {
        return (string) $this->config['fee_mode'];
    }

    /** One line to show buyers before they pay when a fee is added at payment; '' for the default. */
    public function feeNotice(): string
    {
        return FeeMode::buyerNotice($this->config['fee_mode']);
    }

    /** The 2% Caravel fee of an amount, rounded half up to cents. */
    public static function fee(string $amount): string
    {
        $cents = (int) Amount::toCents($amount);
        $fee = intdiv($cents * 2 + 50, 100);
        return intdiv($fee, 100) . '.' . str_pad((string) ($fee % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "Payout: 133.68 USDC, tx 0x…" with an explorer link, for notes. */
    public function payoutNote(array $checkout): string
    {
        $parts = [];
        foreach (Checkout::payouts($checkout) as $payout) {
            $hash = strtolower((string) $payout['tx_hash']);
            $asset = isset($payout['asset']) && is_string($payout['asset']) ? $payout['asset'] : null;
            $parts[] = $payout['amount'] . ' ' . $payout['asset'] . ', tx ' . $hash . ' (' . Checkout::explorerUrl($hash, null, $asset) . ')';
        }
        return $parts ? 'Payout: ' . implode('; ', $parts) . '.' : '';
    }

    private function orderId(string $ref, string $salt, int $attempt): string
    {
        $id = $this->config['order_prefix'] . $ref . '-' . $salt;
        return $attempt > 1 ? $id . '-' . $attempt : $id;
    }

    private static function salt(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $salt = '';
        for ($i = 0; $i < 6; $i++) {
            $salt .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $salt;
    }

    private function matches(array $credentials): bool
    {
        return 0 === strcasecmp((string) $credentials['payout_address'], trim((string) $this->config['payout_address']))
            && $credentials['site_url'] === $this->config['site_url'];
    }

    /** @return array<string, string> merchant id => secret, for webhook verification. */
    private function secrets(int $now): array
    {
        $secrets = [];
        foreach ($this->storage->credentials() as $row) {
            $merchant = (string) $row['merchant_id'];
            if ('current' === $row['status'] || (int) $row['retired_at'] >= $now - self::KEEP_RETIRED_SECONDS
                || $this->storage->latestCheckoutAt($merchant) >= $now - self::KEEP_RETIRED_SECONDS) {
                $secrets[$merchant] = (string) $row['secret'];
            }
        }
        return $secrets;
    }

    private function credentialsFor(string $merchantId): ?array
    {
        foreach ($this->storage->credentials() as $row) {
            if ($row['merchant_id'] === $merchantId) {
                return $row;
            }
        }
        return null;
    }

    /** Delete replaced credentials 7 days after they were replaced and after their last checkout. */
    private function purge(int $now): void
    {
        foreach ($this->storage->credentials() as $row) {
            if ('retired' === $row['status'] && (int) $row['retired_at'] < $now - self::KEEP_RETIRED_SECONDS
                && $this->storage->latestCheckoutAt((string) $row['merchant_id']) < $now - self::KEEP_RETIRED_SECONDS) {
                $this->storage->deleteCredentials((string) $row['merchant_id']);
            }
        }
    }

    private function connectFailure(ApiException $e, int $now): ConnectException
    {
        $code = $e->getErrorCode();
        $this->ledger->log('error', 'Connecting to Caravel Pay failed: ' . $code . (null !== $e->getParam() ? ' (' . $e->getParam() . ')' : '') . ', HTTP ' . $e->getHttpStatus() . '.');
        if ($e->isRetryable()) {
            $wait = null !== $e->getRetryAfter() ? $e->getRetryAfter() : 60;
            $this->storage->saveSetting('connect_retry_at', (string) ($now + min(3600, max(30, $wait))));
            return new ConnectException('rate_limited' === $code ? 'rate_limited' : 'unavailable', 'Caravel Pay is busy. Please try again in a few minutes.');
        }
        if ('invalid_url' === $code) {
            return new ConnectException('invalid_url', 'Caravel Pay refused your site address: it must be the public HTTPS address of this site. ' . $e->getMessage());
        }
        if ('limit_exceeded' === $code) {
            return new ConnectException('limit_exceeded', $e->getMessage());
        }
        if ('payout_address' === $e->getParam()) {
            return new ConnectException('invalid_wallet', $e->getMessage());
        }
        return new ConnectException('error', 'Caravel Pay refused the connection (' . $code . '): ' . $e->getMessage());
    }

    private function client(array $credentials): Client
    {
        return new Client((string) $credentials['merchant_id'], (string) $credentials['secret'], $this->clientOptions());
    }

    private function clientOptions(): array
    {
        $options = ['user_agent' => 'CaravelPay-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $this->config['platform_name']) . '/' . $this->config['version'], 'clock' => $this->config['clock']];
        foreach (['api_base', 'transport', 'timeout'] as $key) {
            if (isset($this->config[$key]) && '' !== $this->config[$key]) {
                $options[$key] = $this->config[$key];
            }
        }
        return $options;
    }

    private function safeRedirect(string $url): bool
    {
        if (0 === stripos($url, 'https://')) {
            return true;
        }
        return isset($this->config['api_base']) && 0 === stripos((string) $this->config['api_base'], 'http://') && (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#i', $url);
    }

    private function refuse(string $code, string $message): array
    {
        return ['error' => $code, 'message' => $message];
    }

    private function unavailable(): array
    {
        return $this->refuse('unavailable', 'This payment method is temporarily unavailable. Please try again in a few minutes or choose another one.');
    }

    private function reply(int $status, string $result): array
    {
        return ['status' => $status, 'body' => $status < 300 ? ['received' => true, 'result' => $result] : ['error' => $result], 'result' => $result];
    }
}
