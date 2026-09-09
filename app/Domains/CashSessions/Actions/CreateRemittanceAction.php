<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\RemittanceStatus;
use App\Domains\CashSessions\Exceptions\RemittanceExceedsCash;
use App\Domains\CashSessions\Exceptions\SessionClosed;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;

/**
 * Records a remittance as PENDING. Confirmation — the second half of the
 * segregation-of-duties control — is ConfirmRemittanceAction's job, by a
 * different user (see that class and ConfirmationRequiresSecondUser).
 *
 * The amount is checked against the LIVE expected-cash figure computed by
 * ReconcileCashSessionAction, not a stored balance — see
 * RemittanceExceedsCash's docblock for why it has to be the live number.
 * Also rejected on a closed session (SessionClosed): a session's expected
 * figure is frozen at close, so a remittance recorded afterward would be
 * checked against a number that can no longer move to account for it.
 */
class CreateRemittanceAction
{
    public function __construct(
        private readonly ReconcileCashSessionAction $reconcile,
    ) {}

    /**
     * @param  array{amount_cents: int, note?: string|null}  $payload
     *
     * @throws SessionClosed
     * @throws RemittanceExceedsCash
     */
    public function execute(CashSession $cashSession, array $payload, User $creator): CashRemittance
    {
        if (! $cashSession->isOpen()) {
            throw new SessionClosed($cashSession->getKey());
        }

        $expectedCashCents = $this->reconcile->expectedCashCents($cashSession);
        $amountCents = $payload['amount_cents'];

        if ($amountCents > $expectedCashCents) {
            throw new RemittanceExceedsCash($amountCents, $expectedCashCents);
        }

        $remittance = new CashRemittance([
            'amount_cents' => $amountCents,
            'note' => $payload['note'] ?? null,
            'created_by_user_id' => $creator->getKey(),
        ]);

        $remittance->cashSession()->associate($cashSession);

        // Assigned directly rather than relying on the column default:
        // `status` is deliberately not fillable (see the model docblock),
        // so create() would leave the in-memory attribute unset even
        // though the stored row got `pending` from the column default —
        // and the freshly-returned model is what CashRemittanceResource
        // reads status->value from immediately after this call.
        $remittance->status = RemittanceStatus::Pending;
        $remittance->save();

        return $remittance;
    }
}
