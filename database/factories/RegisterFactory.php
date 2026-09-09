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
            'name' => 'Front Counter',
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
