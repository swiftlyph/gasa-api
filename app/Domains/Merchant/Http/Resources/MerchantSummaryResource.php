<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact merchant shape embedded in /auth/me. Deliberately minimal —
 * enough for a frontend to render the merchant name and branch on status
 * (e.g. show the suspended screen), not a full merchant representation.
 *
 * @mixin Merchant
 */
class MerchantSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,

            // P10: additive, so the POS can render the right tax lines on
            // a slip without a second request for the full profile.
            'vat_registered' => $this->vat_registered,

            // Loaded via $user->merchants->first() in UserResource, so
            // the pivot (role_in_merchant) is attached the same way
            // TeamMemberResource reads it — see that resource's docblock
            // for why getAttribute() rather than magic property access.
            // Guarded rather than assumed present: this resource is
            // reachable from anywhere a Merchant is wrapped, not only
            // through that relation.
            'role_in_merchant' => $this->when($this->resource->relationLoaded('pivot'), function () {
                /** @var Pivot $pivot */
                $pivot = $this->resource->pivot;

                /** @var string $role */
                $role = $pivot->getAttribute('role_in_merchant');

                return RoleInMerchant::from($role)->value;
            }),
        ];
    }
}
