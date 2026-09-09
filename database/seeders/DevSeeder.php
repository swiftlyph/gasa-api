<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
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

            // Second merchant: exists so cross-tenant leakage is testable
            // and manually checkable — with only one merchant account you
            // cannot tell correct scoping from no scoping at all.
            ['email' => 'merchant2@gasa.test', 'name' => 'Merchant Two Owner', 'role' => 'merchant'],

            // Suspended merchant: drives the 403 merchant_inactive path and
            // the frontend's suspended screen.
            ['email' => 'suspended@gasa.test', 'name' => 'Suspended Merchant Owner', 'role' => 'merchant'],
        ];

        $users = [];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );

            $user->syncRoles([$account['role']]);

            $users[$account['email']] = $user;
        }

        $this->seedMerchant('Merchant One', MerchantStatus::Active, $users['merchant@gasa.test']);
        $this->seedMerchant('Merchant Two', MerchantStatus::Active, $users['merchant2@gasa.test']);
        $this->seedMerchant('Suspended Merchant', MerchantStatus::Suspended, $users['suspended@gasa.test']);
    }

    /**
     * Idempotent: keyed on owner_user_id so re-seeding updates the existing
     * merchant rather than creating a second one for the same owner.
     * syncWithoutDetaching likewise makes the pivot attach safe to re-run.
     */
    private function seedMerchant(string $name, MerchantStatus $status, User $owner): void
    {
        $merchant = Merchant::updateOrCreate(
            ['owner_user_id' => $owner->id],
            ['name' => $name, 'status' => $status],
        );

        $merchant->users()->syncWithoutDetaching([
            $owner->id => ['role_in_merchant' => 'owner'],
        ]);
    }
}
