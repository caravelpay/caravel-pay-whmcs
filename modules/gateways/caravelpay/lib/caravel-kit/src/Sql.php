<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * The few SQL operations SqlStorage needs, so it runs on PDO (PdoSql) or a platform's own database
 * layer (for example WordPress's $wpdb). Placeholders are "?". Every method throws on failure.
 */
interface Sql
{
    /** "mysql" or "sqlite": decides the CREATE TABLE options. */
    public function driver(): string;

    public function exec(string $sql): void;

    /** @param array<int, string|int|null> $params @return int Affected rows. */
    public function run(string $sql, array $params): int;

    /** @param array<int, string|int|null> $params @return array<string, mixed>|null */
    public function one(string $sql, array $params): ?array;

    /** @param array<int, string|int|null> $params @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params): array;
}
