<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Merchant\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /admin/merchants. Deliberately compact — full profile,
 * team, and status history are what GET /admin/merchants/{merchant}
 * (AdminMerchantDetailResource) is for.
 *
 * Every value here must come from the eager-loaded/aggregated query the
 * controller already built (`owner`, `users_count`, `registers_exists`)
 * — see AdminMerchantController::index()'s docblock for why: touching an
 * unloaded relation here would turn "no N+1" into "N+1 hidden inside a
 * resource", the same failure mode this API's bounded-query-count tests
 * exist to catch.
 *
 * @mixin Merchant
 */
class AdminMerchantListResource extends JsonResource
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
            'owner' => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => (string) $this->owner->email,
            ],
            'team_size' => $this->resource->getAttribute('users_count'),
            'has_register' => (bool) $this->resource->getAttribute('registers_exists'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
