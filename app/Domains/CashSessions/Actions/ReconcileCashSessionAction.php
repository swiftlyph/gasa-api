<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\CashSessions\Enums\CashMovementType;
use App\Domains\CashSessions\Enums\RemittanceStatus;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;

/**
 * THE single authority on "how much cash should be in this drawer right
 * now." Nothing else in the codebase computes this figure — not the
 * close endpoint, not a report, not a resource — everything asks this
 * class, so there is exactly one formula to audit, and exactly one place
 * a fix ever needs to be made.
 *
 * Expected cash is DERIVED, every time it is asked for, never stored as a
 * running total that is incremented on each event. The audited system's
 * single-blob-per-day design invited exactly that drift: a running total
 * that silently disagrees with its inputs the moment one write is missed,
 * with no way to tell after the fact whether the number or the ledger is
 * wrong. Deriving it fresh from `orders`, `cash_movements` and
 * `cash_remittances` means the figure can never drift from its own
 * inputs — it can only be recomputed.
 *
 * The formula, in the order its terms are summed:
 *
 *     expected_cash =
 *         opening_float
 *       + cash sales attributed to the session
 *           (COMPLETED and PENDING orders — a sale is money in the drawer
 *           the moment it's rung up, not only once it's marked complete)
 *       − VOIDED orders' cash contribution
 *       + cash_in movements − cash_out movements
 *       − CONFIRMED remittances
 *
 * "Cash sales" means the CASH PORTION only: a pure-cash order's full
 * total_cents, plus a split order's cash_cents half. GCASH NEVER COUNTS —
 * gcash settles electronically and never touches the physical till, so
 * gcash_cents (whether from a pure-gcash or a split order) contributes
 * nothing to this figure, at any point in the formula. A voided order
 * contributes nothing in EITHER direction if it was gcash-only, and
 * subtracts its cash portion if it had one — you cannot void money out of
 * a drawer that a gcash sale never put there.
 *
 * Only CONFIRMED remittances subtract. A pending remittance is a claim
 * that cash was taken out, not proof — counting it before confirmation
 * would let an unconfirmed (or fraudulent) remittance make the drawer
 * look short of cash it still physically holds.
 */
class ReconcileCashSessionAction
{
    /**
     * @return array{
     *     opening_float_cents: int,
     *     cash_sales_cents: int,
     *     voided_cash_cents: int,
     *     cash_in_cents: int,
     *     cash_out_cents: int,
     *     confirmed_remittances_cents: int,
     *     expected_cash_cents: int,
     * }
     */
    public function execute(CashSession $cashSession): array
    {
        $openingFloatCents = $cashSession->opening_float_cents;

        $cashSalesCents = $this->cashContributionOf($cashSession, excludeVoided: true);
        $voidedCashCents = $this->cashContributionOf($cashSession, excludeVoided: false)
            - $cashSalesCents;

        $cashInCents = $this->movementTotal($cashSession, CashMovementType::CashIn);
        $cashOutCents = $this->movementTotal($cashSession, CashMovementType::CashOut);

        $confirmedRemittancesCents = (int) $cashSession->remittances()
            ->where('status', RemittanceStatus::Confirmed)
            ->sum('amount_cents');

        $expectedCashCents = $openingFloatCents
            + $cashSalesCents
            - $voidedCashCents
            + $cashInCents
            - $cashOutCents
            - $confirmedRemittancesCents;

        return [
            'opening_float_cents' => $openingFloatCents,
            'cash_sales_cents' => $cashSalesCents,
            'voided_cash_cents' => $voidedCashCents,
            'cash_in_cents' => $cashInCents,
            'cash_out_cents' => $cashOutCents,
            'confirmed_remittances_cents' => $confirmedRemittancesCents,
            'expected_cash_cents' => $expectedCashCents,
        ];
    }

    public function expectedCashCents(CashSession $cashSession): int
    {
        return $this->execute($cashSession)['expected_cash_cents'];
    }

    /**
     * The cash portion of this session's orders — total_cents for a pure
     * cash order, cash_cents for a split, nothing for gcash — either
     * counting only live (pending/completed) orders, or counting every
     * order including voided ones, so the caller can subtract the two and
     * isolate exactly what a void takes back out.
     *
     * Built as one query per case rather than pulling every order into
     * PHP and summing there: this runs every time a session's live figure
     * is requested (GET .../current, and the summary embedded in every
     * session read), so it has to stay a single aggregate query rather
     * than scale with the session's order count.
     */
    private function cashContributionOf(CashSession $cashSession, bool $excludeVoided): int
    {
        $query = $cashSession->orders()->getQuery();

        if ($excludeVoided) {
            $query->where('status', '!=', OrderStatus::Voided->value);
        }

        $cashTotal = (int) (clone $query)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->sum('total_cents');

        $splitCashTotal = (int) (clone $query)
            ->where('payment_method', PaymentMethod::Split->value)
            ->sum('cash_cents');

        return $cashTotal + $splitCashTotal;
    }

    private function movementTotal(CashSession $cashSession, CashMovementType $type): int
    {
        return (int) $cashSession->movements()
            ->where('type', $type->value)
            ->sum('amount_cents');
    }
}
