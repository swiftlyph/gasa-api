<?php

namespace App\Domains\Platform\Http\Resources;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\RoleInMerchant;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of GET /admin/users. Deliberately compact — full detail is what
 * GET /admin/users/{user} is for.
 *
 * Every value comes from the eager-loaded query the controller already
 * built (`roles`, `merchants`), never a lazy relation touch: that would
 * turn "no N+1" into an N+1 hidden inside a resource, the failure mode
 * this API's bounded-query-count tests exist to catch.
 *
 * @mixin User
 */
class AdminUserListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $merchant = $this->merchants->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => (string) $this->email,
            'roles' => $this->roles->pluck('name')->values(),

            // Derived, not stored: deleted_at is the single source of
            // truth for whether an account can sign in.
            'status' => $this->deleted_at === null ? 'active' : 'deactivated',

            'merchant' => $merchant === null ? null : [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status->value,
                'role_in_merchant' => $this->roleInMerchantFor($merchant),
            ],

            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Reads the pivot off the already-loaded relation — see
     * TeamMemberResource for why getAttribute() rather than magic
     * property access.
     */
    private function roleInMerchantFor(object $merchant): ?string
    {
        /** @var Pivot|null $pivot */
        $pivot = $merchant->getAttribute('pivot');

        if ($pivot === null) {
            return null;
        }

        /** @var string $role */
        $role = $pivot->getAttribute('role_in_merchant');

        return RoleInMerchant::from($role)->value;
    }
}
