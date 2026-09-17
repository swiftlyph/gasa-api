<?php

namespace App\Domains\Platform\Http\Resources;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /admin/users/{user} and the 201 body of POST /admin/users.
 *
 * $history is passed in rather than queried here, for the same reason
 * AdminMerchantDetailResource takes $statusHistory: a JsonResource has
 * nowhere to run an extra query without the controller losing sight of
 * how many queries the endpoint makes.
 *
 * NO invite token is ever exposed here. The plaintext exists only in
 * CreateUserAction's return value and is surfaced by the controller in
 * local/development alone — never from a read endpoint, where it would
 * let anyone with list access mint a session for another account.
 *
 * @mixin User
 */
class AdminUserDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, AuditLog>  $history
     */
    public function __construct(User $user, private readonly Collection $history)
    {
        parent::__construct($user);
    }

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
            'status' => $this->deleted_at === null ? 'active' : 'deactivated',

            // Every merchant this user belongs to, with their role on each.
            // A list rather than a single object: merchant_user is
            // many-to-many, and an admin view should show the real shape
            // even while User::merchant() still resolves one active tenant.
            'merchants' => $this->merchants->map(fn ($merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status->value,
                'role_in_merchant' => $this->roleInMerchantFor($merchant),
                'is_owner' => $merchant->owner_user_id === $this->id,
            ])->values(),

            'history' => AuditLogResource::collection($this->history),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deactivated_at' => $this->deleted_at?->toISOString(),
        ];
    }

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
