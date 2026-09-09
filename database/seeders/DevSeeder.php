<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Seeder;

/**
 * One user per role for local development. Idempotent — `updateOrCreate`
 * by email means reseeding never duplicates or errors.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $accounts = [
            ['email' => 'admin@gasa.test', 'name' => 'Platform Admin', 'role' => 'platform_admin'],
            ['email' => 'company@gasa.test', 'name' => 'Company Admin', 'role' => 'company_admin'],
            ['email' => 'employee@gasa.test', 'name' => 'Employee', 'role' => 'employee'],
            ['email' => 'merchant@gasa.test', 'name' => 'Merchant', 'role' => 'merchant'],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );

            $user->syncRoles([$account['role']]);
        }
    }
}
