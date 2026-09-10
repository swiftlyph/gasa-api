<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Factory can't guess `App\Domains\Merchant\Models\Merchant` from the
     * factory name under our Domains layout, so name the model explicitly.
     */
    protected $model = Merchant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => MerchantStatus::Active,
            'owner_user_id' => User::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => MerchantStatus::Pending]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => MerchantStatus::Suspended]);
    }

    /**
     * Makes $user both the owner and a pivot member — the normal shape for
     * a single-merchant account, since BelongsToMerchant resolves tenancy
     * through the pivot, not through ownership.
     */
    public function ownedBy(User $user, string $roleInMerchant = 'owner'): static
    {
        return $this->state(fn () => ['owner_user_id' => $user->id])
            ->afterCreating(function (Merchant $merchant) use ($user, $roleInMerchant) {
                $merchant->users()->syncWithoutDetaching([
                    $user->id => ['role_in_merchant' => $roleInMerchant],
                ]);
            });
    }

    /**
     * Attaches an ADDITIONAL team member with a given role_in_merchant —
     * for P8 permission tests that need a manager/staff alongside the
     * owner ownedBy() already creates. Takes a RoleInMerchant instance
     * rather than a raw string (unlike ownedBy(), kept string-typed for
     * its existing call sites) since every P8 caller already has the
     * enum in hand and there's no reason to round-trip through a string.
     */
    public function withMember(User $user, RoleInMerchant $roleInMerchant): static
    {
        return $this->afterCreating(function (Merchant $merchant) use ($user, $roleInMerchant) {
            $merchant->users()->syncWithoutDetaching([
                $user->id => ['role_in_merchant' => $roleInMerchant->value],
            ]);
        });
    }
}
