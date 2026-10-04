<?php

namespace App\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A gateway callback that could not be verified (bad signature, stale timestamp,
 * malformed body). Rendered as 400; nothing is recorded or changed.
 */
class InvalidGatewayCallbackException extends HttpException
{
    public function __construct(string $message = 'The payment callback could not be verified.')
    {
        parent::__construct(400, $message);
    }
}
