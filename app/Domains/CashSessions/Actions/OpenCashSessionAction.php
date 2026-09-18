<?php

namespace App\Domains\CashSessions\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Enums\CashSessionStatus;
use App\Domains\CashSessions\Exceptions\SessionAlreadyOpen;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\CashSessions\Support\DefaultRegister;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a till shift.
 *
 * The PARTIAL UNIQUE INDEX on cash_sessions (register_id WHERE status =
 * 'open') is the real guard against two open sessions on one register —
 * see that migration. This Action checks first so the common case gets a
 * clean 409 without ever reaching the database, then catches the
 * constraint violation for the race the check-then-act read cannot close
 * on its own: two opens on the same register landing in the same instant
 * both pass the check, and only one insert can win.
 */
class OpenCashSessionAction
{
    public function __construct(
        private readonly RecordMerchantAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array{register_id?: int|null, opening_float_cents: int, notes?: string|null}  $payload
     *
     * @throws SessionAlreadyOpen
     */
    public function execute(array $payload, User $opener): CashSession
    {
        $merchant = $opener->merchant();

        if ($merchant === null) {
            // Unreachable through merchant.api — EnsureMerchantActive has
            // already returned 403 — but mirrors CheckoutAction's guard:
            // this Action must not be able to open an untenanted session
            // if it is ever called from a command or a job.
            throw new ApiException('Your merchant account is not active.', 'merchant_inactive', 403);
        }

        $register = isset($payload['register_id'])
            ? Register::query()->findOrFail($payload['register_id'])
            : DefaultRegister::for($merchant->getKey());

        if ($register->cashSessions()->where('status', 'open')->exists()) {
            throw new SessionAlreadyOpen($register->getKey());
        }

        try {
            return DB::transaction(function () use ($register, $payload, $opener, $merchant): CashSession {
                $session = new CashSession([
                    'register_id' => $register->getKey(),
                    'opened_by_user_id' => $opener->getKey(),
                    'opening_float_cents' => $payload['opening_float_cents'],
                    'opened_at' => now(),
                    'notes' => $payload['notes'] ?? null,
                ]);

                // Assigned directly, not mass-assigned: `status` is
                // deliberately not fillable (see the model docblock), so
                // create() would leave the in-memory attribute unset even
                // though the stored row got `open` from the column
                // default — and the freshly-returned model is what
                // CashSessionResource reads status->value from
                // immediately after this call.
                $session->status = CashSessionStatus::Open;
                $session->save();

                $this->recordAuditLog->execute(
                    actor: $opener,
                    merchant: $merchant,
                    action: MerchantAuditAction::CashSessionOpened,
                    subject: $session,
                    newValues: ['opening_float_cents' => $session->opening_float_cents],
                );

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race: another request opened a session on this
            // register between the check above and this insert. The
            // partial unique index is what actually decided this, not the
            // check — the check only avoids paying for a round trip in
            // the common, uncontended case.
            throw new SessionAlreadyOpen($register->getKey());
        }
    }
}
