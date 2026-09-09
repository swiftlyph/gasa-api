<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A split payment whose parts don't add up to the total.
 *
 * Like the discount check this cannot live in the FormRequest: the total
 * is derived from server-side prices, so the request has nothing valid to
 * compare the two halves against. The same rule is ALSO enforced by the
 * orders_split_payment_check constraint in the database — this exception
 * is what turns it into a clean 422 instead of a 500, and the constraint
 * is what catches anything that ever writes around this Action.
 */
class SplitMismatch extends ApiException
{
    public function __construct(
        public readonly int $cashCents,
        public readonly int $gcashCents,
        public readonly int $totalCents,
    ) {
        parent::__construct(
            'The cash and GCash amounts must add up to the order total.',
            'split_mismatch',
            422,
        );
    }
}
