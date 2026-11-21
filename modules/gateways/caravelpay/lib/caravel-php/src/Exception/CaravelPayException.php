<?php

declare(strict_types=1);

namespace CaravelPay\Exception;

/**
 * Base class of every exception the SDK throws for API, network and webhook problems.
 * Argument mistakes in your own code throw \InvalidArgumentException instead.
 */
abstract class CaravelPayException extends \RuntimeException
{
}
