<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the four fixed platform roles. Idempotent — safe to re-run.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        collect(['platform_admin', 'company_admin', 'employee', 'merchant'])
            ->each(fn (string $role) => Role::findOrCreate($role, 'web'));
    }
}
