<?php

namespace App\Domains\Orders\Support;

use App\Domains\Orders\Models\Order;

/**
 * What a checkout attempt produced, and whether it actually created
 * anything.
 *
 * A bare Order can't carry the second fact, and the controller needs it:
 * a replay answers 200, a fresh checkout answers 201. Returning the pair
 * keeps that decision on data rather than on the controller re-deriving
 * it (by, say, comparing timestamps) and getting it subtly wrong.
 */
class CheckoutResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $replayed,
    ) {}
}
