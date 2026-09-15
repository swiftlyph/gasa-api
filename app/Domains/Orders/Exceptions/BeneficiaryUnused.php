<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A beneficiary was declared on the checkout but no line was assigned to
 * them.
 *
 * Rejected rather than ignored, because the two ways of being lenient are
 * both worse:
 *
 *  - Dropping the beneficiary silently would record a sale with no
 *    discount for a customer who presented a senior/PWD ID at the
 *    counter and watched the cashier type it in. They are owed 20% by
 *    law, and the first anyone would learn of the omission is a
 *    complaint.
 *  - Discounting the whole order instead would give a group's worth of
 *    statutory discount to one beneficiary, which is exactly the abuse
 *    the per-line assignment exists to prevent.
 *
 * So the cashier is told, and assigns the lines. `errors.beneficiaries`
 * names the offending indexes, matching the positional shape the request
 * used (see CheckoutRequest).
 *
 * A 422 raised from the FormRequest rather than the Action: "every
 * declared beneficiary owns at least one line" is decidable from the
 * request alone — it needs no server-side price — so it belongs with the
 * other shape rules. It carries its OWN code rather than
 * `validation_failed` because a client can act on it specifically.
 */
class BeneficiaryUnused extends ApiException
{
    /**
     * @param  list<int>  $indexes  The positions in `beneficiaries` that no
     *                              line referenced.
     */
    public function __construct(public readonly array $indexes)
    {
        parent::__construct(
            'Every beneficiary must have at least one item assigned to them.',
            'beneficiary_unused',
            422,
            ['beneficiaries' => array_map(
                fn (int $index): string => (string) $index,
                $indexes,
            )],
        );
    }
}
