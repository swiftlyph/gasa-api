<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\MerchantAuditLog;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantAuditLog>
 */
class MerchantAuditLogFactory extends Factory
{
    use CreatesAcrossTenants;

    /** @var class-string<MerchantAuditLog> */
    protected $model = MerchantAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'actor_user_id' => User::factory(),
            'action' => 'product.updated',
            'subject_type' => Product::class,
            'subject_id' => Product::factory(),
            'old_values' => ['name' => 'Old name'],
            'new_values' => ['name' => 'New name'],
            'context' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }
}
