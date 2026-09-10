<?php

namespace App\Domains\Merchant\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\UpdateMerchantProfileAction;
use App\Domains\Merchant\Http\Requests\UpdateMerchantProfileRequest;
use App\Domains\Merchant\Http\Resources\MerchantProfileResource;
use App\Domains\Merchant\Models\Merchant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

    public function update(UpdateMerchantProfileRequest $request, UpdateMerchantProfileAction $action): MerchantProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $this->authorize('update', $merchant);

        $updated = $action->execute($merchant, $request->profilePayload());

        return MerchantProfileResource::make($updated);
    }
}
