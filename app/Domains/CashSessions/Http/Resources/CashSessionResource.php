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
     * `cash_sales_cents` and `voided_cash_cents` are the GROSS shape —
     * "taken in" and "given back" as two independent figures, never one
     * netted against the other — so read them together, not in
     * isolation: `cash_sales_cents` is EVERY cash order in the session,
     * voided ones included (money hit the drawer the moment it was rung
     * up), and `voided_cash_cents` is the cash portion of voided orders
     * ALONE, subtracted back out by `expected_cash_cents` as its own
     * term. A session with only a voided cash sale reports a non-zero
     * `cash_sales_cents` alongside an equal `voided_cash_cents` — that
     * pair nets to zero in `expected_cash_cents`, exactly matching the
     * physical drawer, and is not a bug.
     *
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
