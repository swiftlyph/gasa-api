<?php

namespace App\Domains\CashSessions\Http\Resources;

use App\Domains\CashSessions\Actions\ReconcileCashSessionAction;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cash session payload — used by open, current, show, close, and the
 * history list (which wraps it in { data, links, meta }).
 *
 * `reconciliation` is ALWAYS present, but what it reports differs by
 * status: on an OPEN session, every figure is computed fresh, right now,
 * by ReconcileCashSessionAction (see that class — nothing here duplicates
 * its arithmetic); on a CLOSED session, `expected_cash_cents`,
 * `counted_cash_cents` and `variance_cents` are the values FROZEN at
 * close, so a session's historical record never moves even if a
 * correction lands on some other still-open session later.
 *
 * Resolving the Action via app() rather than constructor injection: a
 * JsonResource is instantiated by Laravel's collection/pagination
 * machinery, which doesn't run it through the container with resolvable
 * constructor arguments the way a controller method's dependencies are.
 *
 * @mixin CashSession
 */
class CashSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'register_id' => $this->register_id,
            'status' => $this->status->value,

            'opening_float_cents' => $this->opening_float_cents,
            'opening_float_formatted' => Money::format($this->opening_float_cents, 'PHP'),

            'opened_by_user_id' => $this->opened_by_user_id,
            'closed_by_user_id' => $this->closed_by_user_id,
            'opened_at' => $this->opened_at->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'notes' => $this->notes,

            'reconciliation' => $this->reconciliation(),

            'movements' => $this->whenLoaded(
                'movements',
                fn () => CashMovementResource::collection($this->movements),
            ),
            'remittances' => $this->whenLoaded(
                'remittances',
                fn () => CashRemittanceResource::collection($this->remittances),
            ),
        ];
    }

    /**
     * @return array{
     *     opening_float_cents: int,
     *     cash_sales_cents: int,
     *     voided_cash_cents: int,
     *     cash_in_cents: int,
     *     cash_out_cents: int,
     *     confirmed_remittances_cents: int,
     *     expected_cash_cents: int,
     *     counted_cash_cents: int|null,
     *     variance_cents: int|null,
     * }
     */
    private function reconciliation(): array
    {
        // The breakdown terms (cash_sales_cents etc.) are recomputed here
        // even on a closed session, where they aren't individually
        // stored: they're read-only context on a figure that itself never
        // changes, and recomputing them from immutable (closed-session)
        // data returns the same answer every time, so there is nothing to
        // drift.
        $figures = app(ReconcileCashSessionAction::class)->execute($this->resource);

        if ($this->isOpen()) {
            return [...$figures, 'counted_cash_cents' => null, 'variance_cents' => null];
        }

        // Closed: expected/counted/variance are the values FROZEN at
        // close, not the ones just recomputed above — see this class's
        // docblock.
        return [
            ...$figures,
            'expected_cash_cents' => $this->expected_cash_cents,
            'counted_cash_cents' => $this->counted_cash_cents,
            'variance_cents' => $this->variance_cents,
        ];
    }
}
