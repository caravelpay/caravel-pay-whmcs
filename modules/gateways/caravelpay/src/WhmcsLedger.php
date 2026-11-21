<?php

namespace CaravelPay\Whmcs;

use CaravelPay\Kit\Invoice;
use CaravelPay\Kit\Ledger;

/**
 * WHMCS side of the payment flow: invoices through the local API, payments through
 * addInvoicePayment(), and every outcome in Billing > Gateway Log.
 */
final class WhmcsLedger implements Ledger
{
    const GATEWAY = 'caravelpay';
    const LOG_NAME = 'Caravel Pay';

    /** @var \PDO|null */
    private $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function invoice(string $ref): ?Invoice
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $ref)) {
            return null;
        }
        $invoice = localAPI('GetInvoice', array('invoiceid' => (int) $ref));
        if (!is_array($invoice) || !isset($invoice['result']) || 'success' !== $invoice['result']) {
            return null;
        }
        $platform = isset($invoice['status']) ? (string) $invoice['status'] : '';
        switch ($platform) {
            case 'Unpaid':
            case 'Payment Pending':
                $status = Invoice::UNPAID;
                break;
            case 'Paid':
                $status = Invoice::PAID;
                break;
            case 'Cancelled':
                $status = Invoice::CANCELLED;
                break;
            default:
                $status = Invoice::OTHER;
        }
        $balance = isset($invoice['balance']) ? self::money($invoice['balance']) : '0.00';
        $client = self::client(isset($invoice['userid']) ? (int) $invoice['userid'] : 0);
        return new Invoice($ref, $status, $balance, $client['currency'], 'USD' === $client['currency'] ? $balance : '', $client['email'], $platform);
    }

    public function recordPayment(Invoice $invoice, array $checkout, string $txHash, string $nativeAmount, string $fee): bool
    {
        $data = array(
            'invoice' => $invoice->ref,
            'checkout' => $checkout['id'],
            'status' => $checkout['status'],
            'amount' => $nativeAmount . ' ' . $invoice->nativeCurrency,
            'fee' => $fee,
            'transaction' => $txHash,
        );
        foreach (\CaravelPay\Checkout::payouts($checkout) as $index => $payout) {
            $data['payout' . ($index ? ' ' . ($index + 1) : '')] = $payout['amount'] . ' ' . $payout['asset'] . ' ' . \CaravelPay\Checkout::explorerUrl($payout['tx_hash']);
        }
        if ($this->transactionExists($txHash)) {
            logTransaction(self::LOG_NAME, $data, 'Successful (already recorded)');
            return true;
        }
        $result = addInvoicePayment((int) $invoice->ref, $txHash, $nativeAmount, $fee, self::GATEWAY);
        $recorded = null === $this->pdo ? false !== $result : $this->transactionExists($txHash);
        logTransaction(self::LOG_NAME, $data, $recorded ? 'Successful' : 'Error');
        return $recorded;
    }

    public function flag(Invoice $invoice, string $status, string $message): void
    {
        $labels = array('underpaid' => 'Underpaid', 'review' => 'Review', 'expired' => 'Expired', 'paid_twice' => 'Paid Twice', 'paid_not_open' => 'Needs Review');
        logTransaction(self::LOG_NAME, array('invoice' => $invoice->ref, 'message' => $message), isset($labels[$status]) ? $labels[$status] : ucfirst($status));
        if ('expired' !== $status) {
            $this->addNote($invoice->ref, $message);
        }
    }

    public function log(string $level, string $message, array $context = array()): void
    {
        $labels = array('info' => 'Info', 'warning' => 'Warning', 'error' => 'Error');
        $status = isset($labels[$level]) ? $labels[$level] : 'Info';
        if (0 === strpos($message, 'Webhook rejected')) {
            $status = 'Rejected';
        }
        logTransaction(self::LOG_NAME, array('message' => $message) + $context, $status);
    }

    /** Append a dated line to the invoice's notes (admin-only). */
    public function addNote(string $ref, string $message): void
    {
        $invoice = localAPI('GetInvoice', array('invoiceid' => (int) $ref));
        if (!is_array($invoice) || !isset($invoice['result']) || 'success' !== $invoice['result']) {
            return;
        }
        $notes = isset($invoice['notes']) ? trim((string) $invoice['notes']) : '';
        $line = gmdate('Y-m-d H:i') . ' UTC ' . $message;
        localAPI('UpdateInvoice', array('invoiceid' => (int) $ref, 'notes' => '' === $notes ? $line : $notes . "\n" . $line));
    }

    private function transactionExists(string $txHash): bool
    {
        if (null === $this->pdo) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT id FROM tblaccounts WHERE transid = ? AND gateway = ? LIMIT 1');
        return false !== $statement && $statement->execute(array($txHash, self::GATEWAY)) && false !== $statement->fetchColumn();
    }

    /**
     * @return array{currency: string, email: string|null}
     */
    private static function client(int $clientId): array
    {
        $details = $clientId > 0 ? localAPI('GetClientsDetails', array('clientid' => $clientId, 'stats' => false)) : array();
        $client = is_array($details) && isset($details['client']) && is_array($details['client']) ? $details['client'] : (is_array($details) ? $details : array());
        $currency = isset($client['currency_code']) ? strtoupper((string) $client['currency_code']) : (isset($details['currency_code']) ? strtoupper((string) $details['currency_code']) : '');
        $email = isset($client['email']) && is_string($client['email']) ? $client['email'] : null;
        return array('currency' => $currency, 'email' => $email);
    }

    /** WHMCS amounts ("49.90", 49.9) as two-decimal strings. */
    public static function money($value): string
    {
        try {
            return \CaravelPay\Amount::normalize(is_string($value) ? $value : (float) $value);
        } catch (\InvalidArgumentException $e) {
            return '0.00';
        }
    }
}
