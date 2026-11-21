<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * Storage on the platform's own database (MySQL/MariaDB in production, SQLite in tests), through
 * any Sql executor. Tables are created on first use with the given prefix, for example
 * "mod_caravelpay_". Secrets are passed through the platform's encrypt/decrypt functions when given.
 */
class SqlStorage implements Storage
{
    /** @var Sql */
    private $sql;
    /** @var string */
    private $prefix;
    /** @var callable|null */
    private $encrypt;
    /** @var callable|null */
    private $decrypt;
    /** @var bool */
    private $ready = false;

    public function __construct(Sql $sql, string $prefix, ?callable $encrypt = null, ?callable $decrypt = null)
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
        $this->sql = $sql;
        $this->prefix = $prefix;
        $this->encrypt = $encrypt;
        $this->decrypt = $decrypt;
    }

    /** The table names this storage uses (for uninstall scripts). */
    public function tables(): array
    {
        $tables = [];
        foreach (['credentials', 'invoices', 'checkouts', 'events', 'settings', 'locks'] as $name) {
            $tables[] = $this->prefix . $name;
        }
        return $tables;
    }

    public function credentials(): array
    {
        $rows = $this->all('SELECT * FROM ' . $this->t('credentials') . " ORDER BY CASE WHEN status = 'current' THEN 0 ELSE 1 END, created_at DESC, merchant_id DESC");
        foreach ($rows as &$row) {
            $row['secret'] = null === $this->decrypt ? (string) $row['secret'] : (string) call_user_func($this->decrypt, (string) $row['secret']);
            $row['created_at'] = (int) $row['created_at'];
            $row['retired_at'] = null === $row['retired_at'] ? null : (int) $row['retired_at'];
        }
        return $rows;
    }

    public function addCredentials(array $row): void
    {
        $secret = (string) $row['secret'];
        $this->run('INSERT INTO ' . $this->t('credentials') . ' (merchant_id, secret, payout_address, site_url, status, created_at, retired_at) VALUES (?, ?, ?, ?, ?, ?, NULL)', [
            (string) $row['merchant_id'],
            null === $this->encrypt ? $secret : (string) call_user_func($this->encrypt, $secret),
            (string) $row['payout_address'],
            (string) $row['site_url'],
            (string) $row['status'],
            (int) $row['created_at'],
        ]);
    }

    public function retireCredentials(string $merchantId, int $at): void
    {
        $this->run('UPDATE ' . $this->t('credentials') . " SET status = 'retired', retired_at = ? WHERE merchant_id = ? AND status = 'current'", [$at, $merchantId]);
    }

    public function deleteCredentials(string $merchantId): void
    {
        $this->run('DELETE FROM ' . $this->t('credentials') . ' WHERE merchant_id = ?', [$merchantId]);
    }

    public function invoiceState(string $ref): ?array
    {
        $row = $this->one('SELECT * FROM ' . $this->t('invoices') . ' WHERE invoice_ref = ?', [$ref]);
        if (null === $row) {
            return null;
        }
        $row['attempt'] = (int) $row['attempt'];
        $row['pending'] = null === $row['pending'] || '' === $row['pending'] ? null : json_decode((string) $row['pending'], true);
        $row['current_checkout'] = null === $row['current_checkout'] ? '' : (string) $row['current_checkout'];
        return $row;
    }

    public function saveInvoiceState(array $state): void
    {
        $pending = isset($state['pending']) && is_array($state['pending']) ? json_encode($state['pending'], JSON_UNESCAPED_SLASHES) : null;
        $values = [(string) $state['salt'], (int) $state['attempt'], (string) $state['current_checkout'], $pending, (int) $state['updated_at'], (string) $state['invoice_ref']];
        $updated = $this->run('UPDATE ' . $this->t('invoices') . ' SET salt = ?, attempt = ?, current_checkout = ?, pending = ?, updated_at = ? WHERE invoice_ref = ?', $values);
        // MySQL reports 0 affected rows for an unchanged row, so check before inserting.
        if (0 === $updated && null === $this->invoiceState((string) $state['invoice_ref'])) {
            $this->run('INSERT INTO ' . $this->t('invoices') . ' (salt, attempt, current_checkout, pending, updated_at, invoice_ref) VALUES (?, ?, ?, ?, ?, ?)', $values);
        }
    }

    public function checkout(string $checkoutId): ?array
    {
        $row = $this->one('SELECT * FROM ' . $this->t('checkouts') . ' WHERE checkout_id = ?', [$checkoutId]);
        if (null !== $row) {
            foreach (['expires_at', 'applied', 'created_at', 'updated_at'] as $int) {
                $row[$int] = (int) $row[$int];
            }
        }
        return $row;
    }

    public function saveCheckout(array $row): void
    {
        $columns = ['invoice_ref', 'merchant_id', 'order_id', 'amount', 'native_amount', 'native_currency', 'checkout_url', 'expires_at', 'status', 'paid_amount', 'applied', 'created_at', 'updated_at'];
        $values = [];
        foreach ($columns as $column) {
            $values[] = isset($row[$column]) ? $row[$column] : (in_array($column, ['expires_at', 'applied', 'created_at', 'updated_at'], true) ? 0 : '');
        }
        $values[] = (string) $row['checkout_id'];
        $set = implode(', ', array_map(function ($c) { return $c . ' = ?'; }, $columns));
        if (0 === $this->run('UPDATE ' . $this->t('checkouts') . ' SET ' . $set . ' WHERE checkout_id = ?', $values) && null === $this->checkout((string) $row['checkout_id'])) {
            $this->run('INSERT INTO ' . $this->t('checkouts') . ' (' . implode(', ', $columns) . ', checkout_id) VALUES (' . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')', $values);
        }
    }

    public function latestCheckoutAt(string $merchantId): int
    {
        $row = $this->one('SELECT MAX(created_at) AS latest FROM ' . $this->t('checkouts') . ' WHERE merchant_id = ?', [$merchantId]);
        return null === $row || null === $row['latest'] ? 0 : (int) $row['latest'];
    }

    public function eventSeen(string $eventId): bool
    {
        return null !== $this->one('SELECT event_id FROM ' . $this->t('events') . ' WHERE event_id = ?', [$eventId]);
    }

    public function rememberEvent(string $eventId, int $at): void
    {
        if (!$this->eventSeen($eventId)) {
            $this->run('INSERT INTO ' . $this->t('events') . ' (event_id, at) VALUES (?, ?)', [$eventId, $at]);
        }
    }

    public function forgetEventsBefore(int $at): void
    {
        $this->run('DELETE FROM ' . $this->t('events') . ' WHERE at < ?', [$at]);
    }

    public function setting(string $name): ?string
    {
        $row = $this->one('SELECT value FROM ' . $this->t('settings') . ' WHERE name = ?', [$name]);
        return null === $row ? null : (string) $row['value'];
    }

    public function saveSetting(string $name, string $value): void
    {
        if (0 === $this->run('UPDATE ' . $this->t('settings') . ' SET value = ? WHERE name = ?', [$value, $name]) && null === $this->setting($name)) {
            $this->run('INSERT INTO ' . $this->t('settings') . ' (name, value) VALUES (?, ?)', [$name, $value]);
        }
    }

    public function lock(string $name, int $ttlSeconds, int $now): ?string
    {
        $token = bin2hex(random_bytes(16));
        // Take over an expired lock, otherwise insert a new one; the primary key makes the insert exclusive.
        $this->run('DELETE FROM ' . $this->t('locks') . ' WHERE name = ? AND expires < ?', [$name, $now]);
        try {
            $inserted = $this->run('INSERT INTO ' . $this->t('locks') . ' (name, token, expires) VALUES (?, ?, ?)', [$name, $token, $now + $ttlSeconds]);
        } catch (\Exception $e) {
            return null; // Duplicate key: someone else holds it.
        }
        return $inserted > 0 ? $token : null;
    }

    public function unlock(string $name, string $token): void
    {
        $this->run('DELETE FROM ' . $this->t('locks') . ' WHERE name = ? AND token = ?', [$name, $token]);
    }

    private function t(string $table): string
    {
        $this->schema();
        return $this->prefix . $table;
    }

    private function schema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->ready = true;
        $mysql = 'mysql' === $this->sql->driver();
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $p = $this->prefix;
        $tables = [
            "CREATE TABLE IF NOT EXISTS {$p}credentials (merchant_id VARCHAR(64) NOT NULL PRIMARY KEY, secret TEXT NOT NULL, payout_address VARCHAR(42) NOT NULL, site_url VARCHAR(2048) NOT NULL, status VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, retired_at BIGINT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}invoices (invoice_ref VARCHAR(64) NOT NULL PRIMARY KEY, salt VARCHAR(16) NOT NULL, attempt INT NOT NULL, current_checkout VARCHAR(140) NULL, pending TEXT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}checkouts (checkout_id VARCHAR(140) NOT NULL PRIMARY KEY, invoice_ref VARCHAR(64) NOT NULL, merchant_id VARCHAR(64) NOT NULL, order_id VARCHAR(128) NOT NULL, amount VARCHAR(20) NOT NULL, native_amount VARCHAR(20) NOT NULL, native_currency VARCHAR(8) NOT NULL, checkout_url VARCHAR(2048) NOT NULL, expires_at BIGINT NOT NULL, status VARCHAR(20) NOT NULL, paid_amount VARCHAR(20) NOT NULL, applied INT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}events (event_id VARCHAR(140) NOT NULL PRIMARY KEY, at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}settings (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}locks (name VARCHAR(140) NOT NULL PRIMARY KEY, token VARCHAR(64) NOT NULL, expires BIGINT NOT NULL)",
        ];
        foreach ($tables as $sql) {
            $this->exec($sql . $suffix);
        }
        $index = $mysql ? "CREATE INDEX {$p}checkouts_invoice ON {$p}checkouts (invoice_ref)" : "CREATE INDEX IF NOT EXISTS {$p}checkouts_invoice ON {$p}checkouts (invoice_ref)";
        if (!$mysql || null === $this->one("SHOW INDEX FROM {$p}checkouts WHERE Key_name = ?", ["{$p}checkouts_invoice"])) {
            $this->exec($index);
        }
    }

    private function exec(string $sql): void
    {
        $this->sql->exec($sql);
    }

    /** @param array<int, mixed> $params */
    private function run(string $sql, array $params): int
    {
        return $this->sql->run($sql, $params);
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->sql->one($sql, $params);
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        return $this->sql->all($sql, $params);
    }
}
