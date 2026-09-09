<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Shared\Concerns\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * One user per role for local development, plus a demo catalog and order
 * history for the two active merchants. Idempotent throughout —
 * `updateOrCreate` by email/name for users, products and merchants, and
 * orders are skipped entirely for a merchant that already has some, so
 * reseeding never duplicates or errors.
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

        $merchantOne = $this->seedMerchant('Merchant One', MerchantStatus::Active, $users['merchant@gasa.test']);
        $merchantTwo = $this->seedMerchant('Merchant Two', MerchantStatus::Active, $users['merchant2@gasa.test']);
        $this->seedMerchant('Suspended Merchant', MerchantStatus::Suspended, $users['suspended@gasa.test']);

        // Both active merchants get a catalog and order history. BOTH, not
        // just one: the kitchen and POS frontends demo against this data,
        // and with a single merchant seeded, "correctly scoped" and "not
        // scoped at all" look exactly the same on screen.
        //
        // Wrapped in admin context because a seeder has no authenticated
        // user, so every tenant-scoped read here would otherwise match
        // nothing and every write would fail the merchant_id NOT NULL
        // constraint. This is the sanctioned non-request use of the
        // bypass — see TenantContext::runInAdminContext().
        app(TenantContext::class)->runInAdminContext(function () use ($merchantOne, $merchantTwo, $users): void {
            $this->seedCatalogAndOrders($merchantOne, $users['merchant@gasa.test']);
            $this->seedCatalogAndOrders($merchantTwo, $users['merchant2@gasa.test']);
        });
    }

    /**
     * Idempotent: keyed on owner_user_id so re-seeding updates the existing
     * merchant rather than creating a second one for the same owner.
     * syncWithoutDetaching likewise makes the pivot attach safe to re-run.
     */
    private function seedMerchant(string $name, MerchantStatus $status, User $owner): Merchant
    {
        $merchant = Merchant::updateOrCreate(
            ['owner_user_id' => $owner->id],
            ['name' => $name, 'status' => $status],
        );

        $merchant->users()->syncWithoutDetaching([
            $owner->id => ['role_in_merchant' => 'owner'],
        ]);

        return $merchant;
    }

    /**
     * A small menu and a realistic spread of orders for one merchant.
     *
     * The states are chosen so a frontend has something to render for
     * every branch it has to handle: an open ticket (pending), a closed
     * sale (completed), a reversal (voided), and all three payment
     * methods including a split whose parts sum to the total.
     */
    private function seedCatalogAndOrders(Merchant $merchant, User $cashier): void
    {
        $products = $this->seedProducts($merchant);

        // Idempotency guard. Orders can't be updateOrCreate'd the way the
        // rows above can — each one issues a fresh sequential number from
        // the merchant's counter, so re-running would append a second
        // batch every time rather than converging on a fixed fixture.
        if (Order::query()->where('merchant_id', $merchant->getKey())->exists()) {
            return;
        }

        $factory = fn () => Order::factory()->forMerchant($merchant, $cashier);

        $factory()->cash()->withItems(2, $products, 2)->create();
        $factory()->gcash()->withItems(1, $products)->create();
        $factory()->split()->withItems(3, $products, 1)->create();

        $factory()->completed()->cash()->withItems(2, $products)->create();
        $factory()->completed()->split()->withItems(1, $products, 2)->create();

        $factory()->voided($cashier)->gcash()->withItems(2, $products)->create();
    }

    /**
     * @return Collection<int, Product>
     */
    private function seedProducts(Merchant $merchant): Collection
    {
        $menu = [
            ['name' => 'Americano (12oz)', 'price_cents' => 9000],
            ['name' => 'Cafe Latte (16oz)', 'price_cents' => 13500],
            ['name' => 'Spanish Latte (16oz)', 'price_cents' => 15000],
            ['name' => 'Matcha Latte (16oz)', 'price_cents' => 16500],
            ['name' => 'Cold Brew (22oz)', 'price_cents' => 18000],
        ];

        return collect($menu)->map(fn (array $item) => Product::updateOrCreate(
            ['merchant_id' => $merchant->getKey(), 'name' => $item['name']],
            ['price_cents' => $item['price_cents'], 'currency' => 'PHP', 'is_available' => true],
        ))->values();
    }
}
