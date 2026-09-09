<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A discount larger than the order it discounts, which would produce a
 * negative total — an order the shop pays the customer for.
 *
 * Checked server-side rather than in the FormRequest because the subtotal
 * is not something the client sends: it is computed here from server-side
 * product prices. A validation rule could only compare the discount
 * against a number the client made up.
 */
class DiscountExceedsSubtotal extends ApiException
{
    public function __construct(
        public readonly int $subtotalCents,
        public readonly int $discountCents,
    ) {
        parent::__construct(
            'The discount is larger than the order subtotal.',
            'discount_exceeds_subtotal',
            422,
        );
    }
}
