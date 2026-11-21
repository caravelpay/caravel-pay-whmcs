<?php

declare(strict_types=1);

namespace CaravelPay\Kit;

/**
 * SqlStorage on a PDO connection (WHMCS, FOSSBilling, Paymenter: the platform's own database).
 */
final class PdoStorage extends SqlStorage
{
    public function __construct(\PDO $pdo, string $prefix, ?callable $encrypt = null, ?callable $decrypt = null)
    {
        parent::__construct(new PdoSql($pdo), $prefix, $encrypt, $decrypt);
    }
}
