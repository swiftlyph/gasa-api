<?php

namespace Database\Factories;

use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Register>
 */
class RegisterFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<Register>
     */
    protected $model = Register::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),

            // Unique per call, not the literal 'Front Counter': every
            // merchant (including the one `Merchant::factory()` implicitly
            // creates above) already gets a "Front Counter" register from
            // P7's EnsureDefaultRegisterAction the moment it's created, so
            // a second register defaulting to that same name would always
            // collide with the table's unique (merchant_id, name) index.
            // Tests that specifically want "the" default register fetch
            // the auto-created one instead of making their own.
            'name' => fake()->unique()->words(2, true).' Counter',
            'is_active' => true,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->getKey()]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
