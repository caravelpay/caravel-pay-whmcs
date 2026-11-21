<?php

declare(strict_types=1);

namespace CaravelPay\Exception;

/**
 * A 2xx response whose body is not what the API documents (not JSON, or a checkout object with a
 * missing or malformed field). Treat it like a failed request: never mark an order paid on it.
 */
final class UnexpectedResponseException extends CaravelPayException
{
}
