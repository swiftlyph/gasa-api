<?php

namespace App\Domains\Merchant\Http\Resources;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A single team member — a User loaded through Merchant::users(), so
 * `$this->pivot` (Eloquent's BelongsToMany attaches it to each related
 * model) carries `role_in_merchant` and the pivot's own `created_at`.
 *
 * `is_owner` needs to know which merchant is being listed, and a
 * JsonResource has no route/request context that supplies that on its
 * own. Of the options available (constructor param, additional(), a
 * static property), a constructor param taken alongside the wrapped User
 * is the cleanest: it keeps the resource a pure function of its inputs
 * (matching RegisterResource, which already takes a second constructor
 * argument — the resolved default register id — for exactly the same
 * reason: per-row context the model itself doesn't carry), rather than
 * reaching for `additional()` (which merges keys onto a collection
 * response, awkward for a single flat resource used both standalone by
 * the controller's store() response and inside a ::collection() list) or
 * a static property (global mutable state shared across unrelated
 * requests).
 *
 * @mixin User
 */
class TeamMemberResource extends JsonResource
{
    public function __construct($resource, private readonly Merchant $merchant)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // BelongsToMany::hydratePivotRelation() sets this dynamically on
        // every related model it loads — User declares no `pivot`
        // property of its own, so PHPStan can't see it without this
        // explicit type: the same shape Laravel apps commonly declare via
        // @property-read on the model itself, done locally here instead
        // since User.php is not ours to touch in this task. Read via
        // getAttribute() rather than magic property access so PHPStan
        // sees a real (mixed) return type instead of an undefined
        // property on Pivot, which likewise declares neither column.
        /** @var Pivot $pivot */
        $pivot = $this->resource->pivot;

        /** @var string $roleInMerchant */
        $roleInMerchant = $pivot->getAttribute('role_in_merchant');

        /** @var Carbon|null $pivotCreatedAt */
        $pivotCreatedAt = $pivot->getAttribute('created_at');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => (string) $this->email,
            // The pivot column has no cast declared on Merchant::users()/
            // User::merchants(), so it already arrives as the plain string
            // value ("owner", "manager", "cashier") — RoleInMerchant::from()
            // is not needed to "unwrap" anything, but round-tripping
            // through it here still guards against a stored value ever
            // drifting from the enum's cases.
            'role_in_merchant' => RoleInMerchant::from($roleInMerchant)->value,
            'is_owner' => $this->id === $this->merchant->owner_user_id,
            'created_at' => $pivotCreatedAt?->toISOString(),
        ];
    }
}
