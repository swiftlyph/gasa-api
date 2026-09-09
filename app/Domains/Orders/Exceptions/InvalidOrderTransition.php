<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Raised by OrderStatus::assertCanTransitionTo() — the only place that
 * decides a transition is illegal. Renders through ApiExceptionRenderer
 * like every other API error: 422 with code `invalid_transition`.
 *
 * 422 rather than 409: the request is well-formed and the order exists,
 * but the operation is not applicable to the entity in its current state,
 * which is the same category as a failed validation rule.
 */
class InvalidOrderTransition extends ApiException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct(
            sprintf('An order that is %s cannot be marked %s.', $from->value, $to->value),
            'invalid_transition',
            422,
        );
    }
}
