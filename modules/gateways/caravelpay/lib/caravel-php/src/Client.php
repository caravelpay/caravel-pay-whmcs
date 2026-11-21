<?php

declare(strict_types=1);

namespace CaravelPay;

use CaravelPay\Exception\ApiException;
use CaravelPay\Exception\NetworkException;
use CaravelPay\Exception\UnexpectedResponseException;
use CaravelPay\Http\CurlTransport;
use CaravelPay\Http\TransportInterface;

/**
 * Caravel Pay API client.
 *
 *     $credentials = Client::connect(['site_url' => 'https://shop.example', 'payout_address' => '0x…']);
 *     $client = new Client($credentials['merchant_id'], $credentials['secret']);
 *     $checkout = $client->createCheckout([...]);
 *
 * Every request except connect is signed with Caravel-Merchant, Caravel-Timestamp and
 * Caravel-Signature (see Signature).
 */
final class Client
{
    public const VERSION = '1.2.1';
    public const DEFAULT_API_BASE = 'https://api.caravelpay.app';
    public const MERCHANT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const OPTIONS = ['api_base', 'timeout', 'connect_timeout', 'transport', 'user_agent', 'max_retries', 'clock', 'sleep', 'ca_bundle'];
    private const CONNECT_FIELDS = ['site_url', 'payout_address', 'email', 'platform', 'plugin_version', 'fee_mode'];
    private const CHECKOUT_FIELDS = ['order_id', 'amount', 'currency', 'customer_email', 'return_url', 'cancel_url', 'callback_url', 'fee_mode', 'fx_buffer_bps'];
    private const LINK_FIELDS = ['amount', 'min_amount', 'max_amount', 'currency', 'description', 'reusable', 'return_url', 'callback_url', 'fee_mode'];
    private const LINK_ID = '/^lnk_[A-Za-z0-9]{8,64}$/D';
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];
    private const MAX_RETRY_WAIT_MS = 10000;

    /** @var string */
    private $merchantId;
    /** @var string */
    private $secret;
    /** @var array<string, mixed> */
    private $config;
    /** @var int|null */
    private $lastStatus;

    /**
     * @param string               $merchantId Your merchant id, for example mch_7Q2KZX4PRB.
     * @param string               $secret     The secret POST /v1/connect returned.
     * @param array<string, mixed> $options    api_base (default https://api.caravelpay.app), timeout (15 s),
     *                                         connect_timeout (10 s), max_retries (0; retries GETs and
     *                                         POST /v1/checkouts on network errors, 429 and 5xx), user_agent
     *                                         (a product token appended to the SDK's, like "MyShop/2.1"),
     *                                         ca_bundle, transport (a TransportInterface), clock and sleep
     *                                         (callables, for tests).
     */
    public function __construct(string $merchantId, string $secret, array $options = [])
    {
        if (!preg_match(self::MERCHANT_ID_PATTERN, $merchantId)) {
            throw new \InvalidArgumentException('The merchant id must look like mch_7Q2KZX4PRB.');
        }
        if (!preg_match('/^[\x21-\x7e]{1,256}$/D', $secret)) {
            throw new \InvalidArgumentException('The secret must be the one POST /v1/connect returned.');
        }
        $this->merchantId = $merchantId;
        $this->secret = $secret;
        $this->config = self::config($options);
    }

    /**
     * POST /v1/connect (unsigned): create a merchant and get its id and secret.
     *
     * Every call creates a NEW merchant; it is never retried automatically. Store the returned secret
     * on your server only: it is not shown again. To change the payout wallet or rotate the secret,
     * connect again and keep the old credentials until their open checkouts have finished.
     *
     * @param array<string, string> $params  site_url, payout_address, optional email, platform ("api" by
     *                                       default, or "woocommerce"), optional plugin_version, optional fee_mode
     *                                       (your default for who pays the fees: see FeeMode).
     * @param array<string, mixed>  $options Same options as the constructor.
     *
     * @return array{merchant_id: string, secret: string, status: string, fee_rate: string}
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public static function connect(array $params, array $options = []): array
    {
        self::onlyFields($params, self::CONNECT_FIELDS, 'connect');
        self::assertFeeMode($params);
        $params += ['platform' => 'api'];
        $body = [];
        foreach (self::CONNECT_FIELDS as $field) {
            if (!array_key_exists($field, $params) || null === $params[$field]) {
                continue;
            }
            if (!is_string($params[$field])) {
                throw new \InvalidArgumentException($field . ' must be a string.');
            }
            $body[$field] = $params[$field];
        }
        foreach (['site_url', 'payout_address'] as $field) {
            if (!isset($body[$field]) || '' === $body[$field]) {
                throw new \InvalidArgumentException($field . ' is required.');
            }
        }
        $config = self::config($options);
        $result = self::send($config, 'POST', '/v1/connect', self::encode($body), [], null, false);
        if (!isset($result['merchant_id'], $result['secret'], $result['status'], $result['fee_rate'])
            || !is_string($result['merchant_id']) || !preg_match('/^mch_[A-Za-z0-9]{4,60}$/D', $result['merchant_id'])
            || !is_string($result['secret']) || !preg_match('/^[\x21-\x7e]{16,256}$/D', $result['secret'])
            || !is_string($result['status']) || !is_string($result['fee_rate'])) {
            throw new UnexpectedResponseException('Caravel Pay returned an unexpected connect response.');
        }
        return [
            'merchant_id' => $result['merchant_id'],
            'secret' => $result['secret'],
            'status' => $result['status'],
            'fee_rate' => $result['fee_rate'],
        ];
    }

    /** The merchant id this client signs with. */
    public function merchantId(): string
    {
        return $this->merchantId;
    }

    /** The API origin this client talks to. */
    public function apiBase(): string
    {
        return $this->config['api_base'];
    }

    /**
     * HTTP status of this client's last response, or null before any. After createCheckout(): 201 for a
     * new checkout, 200 when the order_id already had one with the same details.
     */
    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    /**
     * POST /v1/checkouts: create a hosted checkout and redirect the buyer to its checkout_url.
     *
     * Sending the same order_id again with the same details returns the existing checkout, so a
     * timeout or 5xx can be retried safely. A different amount, email or URL for the same order_id
     * fails with order_id_conflict.
     *
     * @param array<string, mixed> $params   order_id, amount ("136.41"), currency ("USD", the default, or your store's
     *                                       own: see Currency; the amount is then in that currency's decimals, like
     *                                       "45.00" for EUR or "5000" for JPY, and is converted to US dollars now),
     *                                       optional customer_email, return_url, cancel_url, callback_url,
     *                                       optional fee_mode ("seller", "split" or "buyer": see FeeMode;
     *                                       without it your merchant default applies), optional fx_buffer_bps
     *                                       (an integer, 0 to 500: your margin on the converted amount; only with
     *                                       a currency other than USD).
     * @param array<string, string> $options idempotency_key: optional Idempotency-Key header.
     *
     * @return array<string, mixed> {id, checkout_url, status, amount, currency, expires_at}, plus fee_mode and
     *                              buyer_total_estimate when the buyer pays a fee (split or buyer), plus usd_amount
     *                              and fx_rate when the currency isn't USD (amount and currency stay yours).
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function createCheckout(array $params, array $options = []): array
    {
        self::onlyFields($params, self::CHECKOUT_FIELDS, 'checkout');
        self::assertFeeMode($params);
        self::onlyFields($options, ['idempotency_key'], 'option');
        $params += ['currency' => 'USD'];
        if (isset($params['order_id']) && is_int($params['order_id'])) {
            $params['order_id'] = (string) $params['order_id'];
        }
        if (isset($params['amount']) && !is_string($params['amount'])) {
            throw new \InvalidArgumentException('amount must be a string with two decimals, like "136.41". Amount::normalize() converts platform totals.');
        }
        $body = [];
        foreach (self::CHECKOUT_FIELDS as $field) {
            if (!array_key_exists($field, $params) || null === $params[$field]) {
                continue;
            }
            if ('fx_buffer_bps' === $field) {
                // Your margin on a converted amount. 0 is the default and is not sent, so a request without one is unchanged.
                if (!is_int($params[$field]) || $params[$field] < 0 || $params[$field] > Currency::MAX_BUFFER_BPS) {
                    throw new \InvalidArgumentException('fx_buffer_bps must be an integer from 0 to ' . Currency::MAX_BUFFER_BPS . ' (100 is 1%).');
                }
                if (0 !== $params[$field]) {
                    if (Currency::USD === $params['currency']) {
                        throw new \InvalidArgumentException('fx_buffer_bps only applies to a currency other than USD.');
                    }
                    $body[$field] = $params[$field];
                }
                continue;
            }
            if (!is_string($params[$field])) {
                throw new \InvalidArgumentException($field . ' must be a string.');
            }
            $body[$field] = $params[$field];
        }
        $headers = [];
        if (isset($options['idempotency_key'])) {
            if (!is_string($options['idempotency_key']) || !preg_match('/^[\x21-\x7e]{8,128}$/D', $options['idempotency_key'])) {
                throw new \InvalidArgumentException('idempotency_key must be 8 to 128 printable ASCII characters.');
            }
            $headers['Idempotency-Key'] = $options['idempotency_key'];
        }
        $checkout = Checkout::validate($this->call('POST', '/v1/checkouts', self::encode($body), $headers, true));
        if (!isset($checkout['checkout_url']) || !is_string($checkout['checkout_url']) || !preg_match('#^https?://#i', $checkout['checkout_url'])) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout without a checkout_url.');
        }
        // A request that names a mode must be answered in that mode: never redirect a buyer to a checkout
        // that charges fees differently from what was asked.
        if (isset($body['fee_mode']) && FeeMode::of($checkout) !== $body['fee_mode']) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout with another fee_mode than requested.');
        }
        // The same goes for the price: the answer must be for the amount and currency that were sent.
        if (isset($body['amount'], $body['currency']) && ($checkout['amount'] !== $body['amount'] || $checkout['currency'] !== $body['currency'])) {
            throw new UnexpectedResponseException('Caravel Pay returned a checkout for another amount or currency than requested.');
        }
        return $checkout;
    }

    /**
     * GET /v1/fx-quote: today's indicative conversion of another currency to US dollars, for telling a
     * buyer what they will be charged before you redirect them ("about $48.60"). Not a promise: the
     * rate of a checkout is fixed when the checkout is created. Cache the rate for a few minutes
     * (Currency::estimateUsd() applies it to an amount) instead of calling this for every page view.
     *
     * @param string      $currency  A supported currency other than USD.
     * @param string|null $amount    Optional amount in that currency's decimals; the answer then has usd_amount.
     * @param int         $bufferBps Optional margin, 0 to 500; only with an amount.
     *
     * @return array<string, mixed> {currency, fx_rate, quoted_at}, plus amount and usd_amount (and fx_buffer_bps) when asked.
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function fxQuote(string $currency, ?string $amount = null, int $bufferBps = 0): array
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || Currency::USD === $currency) {
            throw new \InvalidArgumentException('currency must be a three-letter code other than USD, like "EUR".');
        }
        if ($bufferBps < 0 || $bufferBps > Currency::MAX_BUFFER_BPS || (0 !== $bufferBps && null === $amount)) {
            throw new \InvalidArgumentException('bufferBps must be 0 to ' . Currency::MAX_BUFFER_BPS . ', and needs an amount.');
        }
        if (null !== $amount && !preg_match('/^[0-9]{1,12}(?:\.[0-9]{2})?$/D', $amount)) {
            throw new \InvalidArgumentException('amount must be a string in the currency\'s own decimals, like "45.00" or "5000". Currency::format() converts platform totals.');
        }
        $query = ['currency' => $currency] + (null !== $amount ? ['amount' => $amount] : []) + (0 !== $bufferBps ? ['fx_buffer_bps' => (string) $bufferBps] : []);
        $quote = $this->call('GET', '/v1/fx-quote?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), '', [], true);
        if (!isset($quote['currency'], $quote['fx_rate']) || $quote['currency'] !== $currency || !is_string($quote['fx_rate']) || !preg_match(Checkout::RATE_PATTERN, $quote['fx_rate'])
            || (null !== $amount && (!isset($quote['usd_amount']) || !is_string($quote['usd_amount']) || !preg_match('/^[0-9]{1,13}\.[0-9]{2}$/D', $quote['usd_amount'])))) {
            throw new UnexpectedResponseException('Caravel Pay returned an unexpected exchange-rate quote.');
        }
        return $quote;
    }

    /**
     * GET /v1/checkouts/{id}: the checkout with its current status, paid_amount and, once paid, the payout.
     * Use this, not the webhook body, to decide whether an order is paid.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function getCheckout(string $checkoutId): array
    {
        if (!preg_match(Checkout::ID_PATTERN, $checkoutId)) {
            throw new \InvalidArgumentException('The checkout id must look like chk_8Qe2TnV4kR.');
        }
        return Checkout::validate($this->call('GET', '/v1/checkouts/' . $checkoutId, '', [], true), $checkoutId);
    }

    /**
     * GET /v1/checkouts: one page of checkouts, newest first.
     *
     * @param array{limit?: int, cursor?: string|null} $params limit 1–100 (default 20), cursor = next_cursor.
     *
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string|null}
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function listCheckouts(array $params = []): array
    {
        self::onlyFields($params, ['limit', 'cursor'], 'list');
        $query = [];
        if (isset($params['limit'])) {
            if (!is_int($params['limit']) || $params['limit'] < 1 || $params['limit'] > 100) {
                throw new \InvalidArgumentException('limit must be an integer from 1 to 100.');
            }
            $query['limit'] = (string) $params['limit'];
        }
        if (isset($params['cursor'])) {
            if (!is_string($params['cursor']) || !preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $params['cursor'])) {
                throw new \InvalidArgumentException('cursor must be a next_cursor from a previous page.');
            }
            $query['cursor'] = $params['cursor'];
        }
        $path = '/v1/checkouts' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $page = $this->call('GET', $path, '', [], true);
        if (!isset($page['data']) || !is_array($page['data']) || !array_key_exists('next_cursor', $page)
            || (null !== $page['next_cursor'] && !is_string($page['next_cursor']))) {
            throw new UnexpectedResponseException('Caravel Pay returned an unexpected checkout list.');
        }
        $data = [];
        foreach ($page['data'] as $checkout) {
            $data[] = Checkout::validate($checkout);
        }
        return ['data' => $data, 'next_cursor' => $page['next_cursor']];
    }

    /**
     * Every checkout, newest first, fetching pages as you iterate.
     *
     * @param array{limit?: int} $params Page size (default 100).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function allCheckouts(array $params = []): \Generator
    {
        $limit = isset($params['limit']) ? $params['limit'] : 100;
        $cursor = null;
        do {
            $page = $this->listCheckouts(null === $cursor ? ['limit' => $limit] : ['limit' => $limit, 'cursor' => $cursor]);
            foreach ($page['data'] as $checkout) {
                yield $checkout;
            }
            $cursor = $page['next_cursor'];
        } while (null !== $cursor && '' !== $cursor);
    }

    /**
     * GET /v1/merchant: the connected site, origins, payout wallet, status and fee rate. A cheap way
     * to test credentials.
     *
     * @return array<string, mixed> {id, site_url, origins[], payout_address, status, fee_rate}
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function getMerchant(): array
    {
        $merchant = $this->call('GET', '/v1/merchant', '', [], true);
        if (!isset($merchant['id'], $merchant['status']) || !is_string($merchant['id']) || !is_string($merchant['status'])
            || !isset($merchant['origins']) || !is_array($merchant['origins'])) {
            throw new UnexpectedResponseException('Caravel Pay returned an unexpected merchant object.');
        }
        return $merchant;
    }

    /**
     * POST /v1/payment-links, signed: a shareable link (https://pay.caravelpay.app/l/lnk_…) that pays
     * out to this merchant's wallet. Its checkouts appear in GET /v1/checkouts and send webhooks to
     * callback_url. Never retried automatically (each call creates a link).
     *
     * @param array<string, mixed> $params amount ("25.00", or null to let the buyer choose), min_amount, max_amount
     *                                     (only with a null amount; both are required in a currency other than USD),
     *                                     currency ("USD", the default, or another supported one: see Currency), description
     *                                     (plain text, up to 80 characters), reusable (default true), return_url, callback_url,
     *                                     fee_mode (who pays the fees for this link's checkouts: see FeeMode).
     *
     * @return array<string, mixed> The payment link: id, url, status, amount, …, payments_count, checkouts_count.
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function createPaymentLink(array $params): array
    {
        $body = self::paymentLinkBody($params, self::LINK_FIELDS);
        return self::paymentLink($this->call('POST', '/v1/payment-links', self::encode($body), [], false), true);
    }

    /**
     * POST /v1/payment-links without API keys: a link that pays out to payout_address. Only id and
     * url come back; these links send no webhooks. Never retried automatically.
     *
     * @param array<string, mixed> $params payout_address, amount (or null), min_amount, max_amount, currency, description, reusable, fee_mode.
     * @param array<string, mixed> $options Same options as the constructor.
     *
     * @return array{id: string, url: string}
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public static function createPaymentLinkWithoutKeys(array $params, array $options = []): array
    {
        $body = self::paymentLinkBody($params, array_merge(['payout_address'], array_diff(self::LINK_FIELDS, ['return_url', 'callback_url'])));
        if (!isset($body['payout_address']) || !is_string($body['payout_address']) || '' === $body['payout_address']) {
            throw new \InvalidArgumentException('payout_address is required without API keys.');
        }
        $link = self::paymentLink(self::send(self::config($options), 'POST', '/v1/payment-links', self::encode($body), [], null, false), false);
        return ['id' => $link['id'], 'url' => $link['url']];
    }

    /**
     * GET /v1/payment-links/{id}: the link with its status (active, completed, deactivated) and counts.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function getPaymentLink(string $linkId): array
    {
        self::assertLinkId($linkId);
        return self::paymentLink($this->call('GET', '/v1/payment-links/' . $linkId, '', [], true), true, $linkId);
    }

    /**
     * POST /v1/payment-links/{id}/deactivate: the link stops opening new checkouts; open ones can still
     * be paid. Idempotent, so it is retried per max_retries.
     *
     * @return array<string, mixed> The deactivated link.
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function deactivatePaymentLink(string $linkId): array
    {
        self::assertLinkId($linkId);
        return self::paymentLink($this->call('POST', '/v1/payment-links/' . $linkId . '/deactivate', '{}', [], true), true, $linkId);
    }

    /**
     * @param array<string, mixed> $params
     * @param string[]             $allowed
     *
     * @return array<string, mixed>
     */
    private static function paymentLinkBody(array $params, array $allowed): array
    {
        self::onlyFields($params, $allowed, 'payment link');
        self::assertFeeMode($params);
        $params += ['currency' => 'USD'];
        $body = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            $value = $params[$field];
            if ('reusable' === $field) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('reusable must be true or false.');
                }
            } elseif ('amount' === $field) {
                if (null !== $value && !is_string($value)) {
                    throw new \InvalidArgumentException('amount must be a string with two decimals, like "25.00", or null to let the buyer choose.');
                }
            } elseif (null === $value) {
                continue;
            } elseif (!is_string($value)) {
                throw new \InvalidArgumentException($field . ' must be a string.');
            }
            $body[$field] = $value;
        }
        if (!array_key_exists('amount', $body)) {
            throw new \InvalidArgumentException('amount is required: a string like "25.00", or null to let the buyer choose.');
        }
        return $body;
    }

    /**
     * @param mixed $link
     *
     * @return array<string, mixed>
     */
    private static function paymentLink($link, bool $full, string $expectedId = ''): array
    {
        if (!is_array($link) || !isset($link['id'], $link['url']) || !is_string($link['id']) || !preg_match(self::LINK_ID, $link['id'])
            || ('' !== $expectedId && $link['id'] !== $expectedId) || !is_string($link['url']) || !preg_match('#^https?://#i', $link['url'])
            || ($full && (!isset($link['status']) || !is_string($link['status'])))) {
            throw new UnexpectedResponseException('Caravel Pay returned an unexpected payment link.');
        }
        return $link;
    }

    private static function assertLinkId(string $linkId): void
    {
        if (!preg_match(self::LINK_ID, $linkId)) {
            throw new \InvalidArgumentException('The payment link id must look like lnk_M5TW3NHJ6DK7Q2ZX4PRB.');
        }
    }

    /**
     * Any signed API call, for endpoints this SDK version doesn't wrap yet. GETs are retried per
     * max_retries; other methods are not.
     *
     * @param string                    $method GET or POST.
     * @param string                    $path   Path with optional query, for example /v1/checkouts?limit=5.
     * @param array<string, mixed>|null $body   JSON body, or null.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException|NetworkException|UnexpectedResponseException
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $method = strtoupper($method);
        return $this->call($method, $path, null === $body ? '' : self::encode($body), [], 'GET' === $method);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $body, array $headers, bool $retryable): array
    {
        $this->lastStatus = null;
        return self::send($this->config, $method, $path, $body, $headers, [$this->merchantId, $this->secret], $retryable, $this->lastStatus);
    }

    /**
     * @param array<string, mixed>       $config
     * @param array<string, string>      $extraHeaders
     * @param array{0: string, 1: string}|null $credentials
     *
     * @return array<string, mixed>
     */
    private static function send(array $config, string $method, string $path, string $body, array $extraHeaders, ?array $credentials, bool $retryable, ?int &$lastStatus = null): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Only GET and POST are supported.');
        }
        if (!preg_match('#^/v1/[\x21-\x7e]*$#D', $path) || false !== strpos($path, '#') || false !== strpos($path, '..')) {
            throw new \InvalidArgumentException('The path must start with /v1/ and contain printable ASCII only.');
        }
        $attempt = 0;
        while (true) {
            $headers = ['Accept' => 'application/json', 'User-Agent' => $config['user_agent']];
            if ('' !== $body) {
                $headers['Content-Type'] = 'application/json';
            }
            if (null !== $credentials) {
                $headers += Signature::headers($credentials[0], $credentials[1], $method, $path, $body, (int) call_user_func($config['clock']));
            }
            $headers += $extraHeaders;
            try {
                $response = $config['transport']->send($method, $config['api_base'] . $path, $headers, $body, $config['timeout'], $config['connect_timeout']);
            } catch (NetworkException $error) {
                if ($retryable && $attempt < $config['max_retries']) {
                    call_user_func($config['sleep'], self::backoff($attempt++));
                    continue;
                }
                throw $error;
            }
            $status = $response->status();
            $lastStatus = $status;
            $raw = $response->body();
            $decoded = preg_match('/^\s*\{/', $raw) ? json_decode($raw, true, 64, JSON_BIGINT_AS_STRING) : null;
            if ($status >= 200 && $status < 300) {
                if (!is_array($decoded)) {
                    throw new UnexpectedResponseException('Caravel Pay returned HTTP ' . $status . ' without a JSON object.', $status);
                }
                return $decoded;
            }
            $error = ApiException::fromResponse($status, $decoded, $response->header('retry-after'));
            if ($retryable && $attempt < $config['max_retries'] && in_array($status, self::RETRY_STATUSES, true)) {
                $wait = null !== $error->getRetryAfter() ? $error->getRetryAfter() * 1000 : self::backoff($attempt);
                if ($wait <= self::MAX_RETRY_WAIT_MS) {
                    call_user_func($config['sleep'], $wait);
                    $attempt++;
                    continue;
                }
            }
            throw $error;
        }
    }

    /** Milliseconds before retry number $attempt + 1: 250, 500, 1000… with jitter. */
    private static function backoff(int $attempt): int
    {
        $base = 250 * (1 << min($attempt, 5));
        return $base + random_int(0, (int) ($base / 4));
    }

    /**
     * JSON exactly as it is sent and signed.
     *
     * @param array<string, mixed> $body
     */
    private static function encode(array $body): string
    {
        if ([] === $body) {
            return '{}';
        }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json) {
            throw new \InvalidArgumentException('The request could not be encoded as JSON (' . json_last_error_msg() . ').');
        }
        return $json;
    }

    /**
     * @param array<string, mixed> $values
     * @param string[]             $allowed
     */
    /**
     * @param array<string, mixed> $params
     */
    private static function assertFeeMode(array $params): void
    {
        if (isset($params['fee_mode']) && !FeeMode::isValid($params['fee_mode'])) {
            throw new \InvalidArgumentException('fee_mode must be "seller", "split" or "buyer".');
        }
    }

    private static function onlyFields(array $values, array $allowed, string $what): void
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown ' . $what . ' field "' . $key . '".');
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function config(array $options): array
    {
        self::onlyFields($options, self::OPTIONS, 'option');
        $base = rtrim(trim(isset($options['api_base']) ? (string) $options['api_base'] : self::DEFAULT_API_BASE), '/');
        if (!self::validApiBase($base)) {
            throw new \InvalidArgumentException('api_base must be an https origin such as https://api.caravelpay.app (http only on localhost).');
        }
        $agent = 'CaravelPay-PHP/' . self::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (isset($options['user_agent']) && '' !== $options['user_agent']) {
            if (!is_string($options['user_agent']) || !preg_match('/^[\x20-\x7e]{1,100}$/D', $options['user_agent'])) {
                throw new \InvalidArgumentException('user_agent must be up to 100 printable ASCII characters.');
            }
            $agent = $options['user_agent'] . ' ' . $agent;
        }
        $transport = isset($options['transport']) ? $options['transport'] : new CurlTransport(isset($options['ca_bundle']) ? ['ca_bundle' => $options['ca_bundle']] : []);
        if (!$transport instanceof TransportInterface) {
            throw new \InvalidArgumentException('transport must implement CaravelPay\\Http\\TransportInterface.');
        }
        foreach (['clock', 'sleep'] as $name) {
            if (isset($options[$name]) && !is_callable($options[$name])) {
                throw new \InvalidArgumentException($name . ' must be callable.');
            }
        }
        return [
            'api_base' => $base,
            'timeout' => isset($options['timeout']) ? min(120, max(1, (int) $options['timeout'])) : 15,
            'connect_timeout' => isset($options['connect_timeout']) ? min(60, max(1, (int) $options['connect_timeout'])) : 10,
            'max_retries' => isset($options['max_retries']) ? min(5, max(0, (int) $options['max_retries'])) : 0,
            'user_agent' => $agent,
            'transport' => $transport,
            'clock' => isset($options['clock']) ? $options['clock'] : 'time',
            'sleep' => isset($options['sleep']) ? $options['sleep'] : static function (int $ms): void {
                usleep($ms * 1000);
            },
        ];
    }

    /** An origin: https, or http on localhost; no path, credentials, query or fragment. */
    public static function validApiBase(string $base): bool
    {
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) || preg_match('/[\s\\\\]/', $base)) {
            return false;
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        $host = strtolower(trim($parts['host'], '[]'));
        return 'https' === $scheme || ('http' === $scheme && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
    }
}
