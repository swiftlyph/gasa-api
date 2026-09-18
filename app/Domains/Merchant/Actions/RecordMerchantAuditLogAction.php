<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\MerchantAuditLog;
use App\Domains\Merchant\Support\MerchantAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * The only place App\Domains\Merchant\Models\MerchantAuditLog::create() is
 * ever called. Every domain Action that mutates a subject on behalf of a
 * merchant (checkout, void, open/close a cash session, edit the catalog,
 * change the team) writes its entry through here rather than inline — one
 * place to get the shape right, mirroring
 * App\Domains\Platform\Actions\RecordAuditLogAction on the admin side.
 *
 * $merchant is passed explicitly rather than left to
 * BelongsToMerchant's auto-stamp-from-Auth::user(): several call sites
 * already carry an explicit Merchant argument of their own (matching e.g.
 * AddTeamMemberAction), and this keeps the entry's tenant unambiguous
 * regardless of which user object happens to be "currently authenticated."
 */
class RecordMerchantAuditLogAction
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        User $actor,
        Merchant $merchant,
        MerchantAuditAction $action,
        Model $subject,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $context = null,
    ): MerchantAuditLog {
        return MerchantAuditLog::query()->create([
            'merchant_id' => $merchant->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'context' => $context,
            'ip_address' => Request::ip(),
        ]);
    }
}
