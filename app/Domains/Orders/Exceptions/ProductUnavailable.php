<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * A checkout referenced products that cannot be sold right now.
 *
 * ONE code covers three different underlying causes, deliberately:
 *
 *   - the product id does not exist at all
 *   - it exists but belongs to a different merchant (the tenant scope
 *     simply never resolved it)
 *   - it exists, is ours, and is flagged is_available = false
 *
 * Splitting these would hand a merchant an existence oracle over their
 * competitors' catalogs: post an id, read whether the answer is "not
 * found" or "unavailable", and you can enumerate the shop next door one
 * integer at a time. From the caller's side all three mean the same
 * thing — "you cannot sell this" — so they get the same answer.
 *
 * The offending ids ARE returned, because they are ids the caller just
 * sent us; echoing them back reveals nothing they didn't already know and
 * lets a POS grey out the exact tiles that failed.
 */
class ProductUnavailable extends ApiException
{
    /**
     * @param  list<int>  $productIds
     */
    public function __construct(public readonly array $productIds)
    {
        parent::__construct(
            'One or more items are no longer available.',
            'product_unavailable',
            422,
            ['product_ids' => array_map(strval(...), $productIds)],
        );
    }
}
