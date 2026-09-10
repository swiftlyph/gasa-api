<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /** @var class-string<AuditLog> */
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'action' => 'merchant.status_changed',
            'subject_type' => Merchant::class,
            'subject_id' => Merchant::factory(),
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'active'],
            'context' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }
}
