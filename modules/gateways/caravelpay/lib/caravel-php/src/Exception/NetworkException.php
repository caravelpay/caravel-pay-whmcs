<?php

declare(strict_types=1);

namespace CaravelPay\Exception;

/**
 * The request did not get an HTTP response: DNS, TLS, connection or timeout failure, or a response
 * over the size limit. The request may or may not have reached Caravel Pay; retrying
 * POST /v1/checkouts with the same order_id is safe.
 */
final class NetworkException extends CaravelPayException
{
}
