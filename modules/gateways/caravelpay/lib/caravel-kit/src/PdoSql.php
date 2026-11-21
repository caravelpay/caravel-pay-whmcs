<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * Sql on a PDO connection, in either error mode.
 */
final class PdoSql implements Sql
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function exec(string $sql): void
    {
        if (false === $this->pdo->exec($sql)) {
            throw new \RuntimeException('Caravel Pay storage: schema statement failed.');
        }
    }

    public function run(string $sql, array $params): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    public function one(string $sql, array $params): ?array
    {
        $row = $this->statement($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function all(string $sql, array $params): array
    {
        return $this->statement($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function statement(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (false === $statement || false === $statement->execute($params)) {
            throw new \RuntimeException('Caravel Pay storage: statement failed.');
        }
        return $statement;
    }
}
