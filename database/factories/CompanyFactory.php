<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\CompanyStatus;
use App\Domains\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Factory can't guess `App\Domains\Company\Models\Company` from the
     * factory name under our Domains layout, so name the model explicitly.
     */
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => CompanyStatus::Active,
            'owner_user_id' => User::factory(),
        ];
    }

    /**
     * The owner is always a member: whoever owns the company also carries
     * its company_id, the way MerchantFactory's ownedBy() always writes
     * the owner's pivot row. Done in configure() so it also holds for a
     * factory-made default owner, not only for ownedBy().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            $owner = $company->owner;

            if ($owner === null || $owner->company_id !== null) {
                return;
            }

            $owner->company_id = $company->getKey();
            $owner->save();
        });
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Pending]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Suspended]);
    }

    /**
     * Makes $user the owner and a member. The membership write happens on
     * the caller's own instance (not a fresh one), so a test that keeps
     * using $user afterwards sees its company_id without a ->fresh().
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_user_id' => $user->id])
            ->afterCreating(function (Company $company) use ($user): void {
                $user->company_id = $company->getKey();
                $user->save();
            });
    }
}
