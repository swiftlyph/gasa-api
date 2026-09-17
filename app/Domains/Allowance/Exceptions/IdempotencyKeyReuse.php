<?php

namespace App\Domains\Allowance\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/** A grant key may only ever represent one exact grant request. */
class IdempotencyKeyReuse extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This idempotency key was already used for a different grant.',
            'idempotency_key_reuse',
            409,
        );
    }
}
