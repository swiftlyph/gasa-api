<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashSessionStatus;
use App\Domains\CashSessions\Exceptions\SessionClosed;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use Illuminate\Support\Facades\DB;

/**
 * Closes a till shift: snapshots expected cash, stores what was counted,
 * and computes the variance between the two — the moment this session's
 * reconciliation figures stop being LIVE and become a permanent record.
 *
 * `expected_cash_cents` is written here and only here. Before close, a
 * caller asking "what's expected right now" gets a figure computed fresh
 * every time (see ReconcileCashSessionAction, called directly by the
 * `current`/`show` endpoints); after close, that figure is frozen at
 * whatever it was at the instant of closing, so a later cash_in/cash_out
 * correction against a *different* still-open session can never reach
 * back and change a closed session's history.
 *
 * `variance_cents = counted − expected`: positive is an OVER (more cash
 * than expected), negative is a SHORT. Not stored as an absolute value —
 * a shop needs to know which direction it went, not just that it
 * disagreed.
 */
class CloseCashSessionAction
{
    public function __construct(
        private readonly ReconcileCashSessionAction $reconcile,
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array{counted_cash_cents: int, notes?: string|null}  $payload
     *
     * @throws SessionClosed
     */
    public function execute(CashSession $cashSession, array $payload, User $closer): CashSession
    {
        if (! $cashSession->isOpen()) {
            throw new SessionClosed($cashSession->getKey());
        }

        return DB::transaction(function () use ($cashSession, $payload, $closer): CashSession {
            $expectedCashCents = $this->reconcile->expectedCashCents($cashSession);
            $countedCashCents = $payload['counted_cash_cents'];

            $cashSession->status = CashSessionStatus::Closed;
            $cashSession->expected_cash_cents = $expectedCashCents;
            $cashSession->counted_cash_cents = $countedCashCents;
            $cashSession->variance_cents = $countedCashCents - $expectedCashCents;
            $cashSession->closed_by_user_id = $closer->getKey();
            $cashSession->closed_at = now();

            if (array_key_exists('notes', $payload) && $payload['notes'] !== null) {
                $cashSession->notes = $payload['notes'];
            }

            $cashSession->save();

            $this->recordAuditLog->execute(
                actor: $closer,
                merchant: $cashSession->merchant,
                action: MerchantAuditAction::CashSessionClosed,
                subject: $cashSession,
                newValues: [
                    'counted_cash_cents' => $cashSession->counted_cash_cents,
                    'variance_cents' => $cashSession->variance_cents,
                ],
            );

            return $cashSession;
        });
    }
}
