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
        ];
    }
}
