<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The same Idempotency-Key arrived with a DIFFERENT request body.
 *
 * Loudly, as a 409, rather than any of the quieter options:
 *
 *  - Serving the original order would answer a request for two lattes
 *    with a receipt for one americano, and the cashier would have no way
 *    to tell.
 *  - Creating the new order would defeat the mechanism entirely, since a
 *    genuine duplicate is exactly "same key, and the body ought to match".
 *
 * A client hitting this has a real bug — almost always reusing one key
 * across checkouts instead of generating one per attempt — and it is
 * better found in development against a 409 than in production against a
 * till that silently disagrees with the customer.
 */
class IdempotencyKeyReuse extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This idempotency key was already used for a different order.',
            'idempotency_key_reuse',
            409,
        );
    }
}
