<?php

namespace App\Domains\Auth\Http\Resources;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Http\Resources\MerchantSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => (string) $this->email,
            'roles' => $this->roles->pluck('name')->values(),

            // Deliberately NOT User::merchant(), which returns only active
            // merchants. /auth/me must still describe a suspended merchant
            // so the frontend can read the status and render its suspended
            // screen — returning null there would look like "not a merchant
            // at all" and bounce the user to a login/onboarding flow.
            // ->first() may be null; MerchantSummaryResource::make(null)
            // would serialize as {} rather than null, so guard explicitly.
            'merchant' => ($merchant = $this->merchants->first())
                ? MerchantSummaryResource::make($merchant)
                : null,

            // P8: this user's merchant permissions, resolved from
            // role_in_merchant via RolePresets. ADDITIVE by design — the
            // frontend is expected to tolerate new keys appearing here
            // later (a custom-role phase) without treating them as
            // unknown/invalid. Deliberately User::merchantPermissions(),
            // NOT derived from the `merchant` key above: merchant() (and
            // therefore permissions) is empty for a merchant that isn't
            // ACTIVE, even though `merchant` itself is still shown so a
            // suspended merchant's frontend can render its status — see
            // `merchant`'s comment. A removed team member (no pivot row
            // at all) gets `merchant: null` and `permissions: []`, same
            // as any other non-merchant account.
            'permissions' => $this->merchantPermissions(),
        ];
    }
}
