<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Raised by MerchantStatus::assertCanTransitionTo() — the only place that
 * decides a merchant status change is illegal. Renders through
 * ApiExceptionRenderer like every other API error: 422 with code
 * `invalid_transition` — the same code App\Domains\Orders\Exceptions\
 * InvalidOrderTransition uses for orders. The error contract has no
 * "domain" field to disambiguate by, so the code is shared across both
 * exception classes by design, exactly like every other reused code in
 * this API.
 *
 * 422 rather than 409, for the same reason as InvalidOrderTransition: the
 * request is well-formed and the merchant exists, but the operation isn't
 * applicable to it in its current state.
 */
class InvalidMerchantTransition extends ApiException
{
    public function __construct(
        public readonly MerchantStatus $from,
        public readonly MerchantStatus $to,
    ) {
        parent::__construct(
            sprintf('A merchant that is %s cannot be marked %s.', $from->value, $to->value),
            'invalid_transition',
            422,
        );
    }
}
