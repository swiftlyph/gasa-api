<?php

namespace App\Domains\Merchant\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\RecordMerchantAuditLogAction;
use App\Domains\Merchant\Actions\UpdateMerchantProfileAction;
use App\Domains\Merchant\Http\Requests\UpdateMerchantProfileRequest;
use App\Domains\Merchant\Http\Resources\MerchantProfileResource;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Support\MerchantAuditAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * GET/PATCH /merchant/profile — the caller's own merchant profile. There
 * is no {merchant} route parameter: the merchant is always "my own
 * merchant", resolved from $user->merchant() exactly like
 * CashSessionController::current() resolves it, never null in practice
 * because EnsureMerchantActive on the merchant.api group already
 * guarantees an active merchant before either action runs.
 */
class MerchantProfileController extends Controller
{
    public function show(Request $request): MerchantProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $this->authorize('view', $merchant);

        return MerchantProfileResource::make($merchant);
    }

    public function update(UpdateMerchantProfileRequest $request, UpdateMerchantProfileAction $action, RecordMerchantAuditLogAction $recordAuditLog): MerchantProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $this->authorize('update', $merchant);

        $payload = $request->profilePayload();
        $before = Arr::only($merchant->getOriginal(), array_keys($payload));

        $updated = $action->execute($merchant, $payload);

        $recordAuditLog->execute(
            actor: $user,
            merchant: $updated,
            action: MerchantAuditAction::ProfileUpdated,
            subject: $updated,
            oldValues: $before,
            newValues: $payload,
        );

        return MerchantProfileResource::make($updated);
    }
}
