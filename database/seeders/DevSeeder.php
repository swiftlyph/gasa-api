<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Actions\CloseCashSessionAction;
use App\Domains\CashSessions\Actions\ConfirmRemittanceAction;
use App\Domains\CashSessions\Actions\ReconcileCashSessionAction;
use App\Domains\CashSessions\Enums\CashMovementType;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Shared\Concerns\TenantContext;
use Illuminate\Database\Seeder;

/**
 * One user per role for local development, plus a demo catalog
 * (ProductSeeder) and order history for the two active merchants.
 * Idempotent throughout — `updateOrCreate` by email/name for users,
 * products and merchants, and orders are skipped entirely for a merchant
 * that already has some, so reseeding never duplicates or errors.
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

            // P4: a second staff member on Merchant One, so remittance
            // confirmation (which REQUIRES a different user than the
            // creator) has someone real to demo against rather than only
            // being exercisable in tests.
            ['email' => 'cashier@gasa.test', 'name' => 'Merchant One Cashier', 'role' => 'merchant'],
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

        // P4's second staff member, attached as a plain member rather than
        // owner — syncWithoutDetaching keeps this idempotent alongside the
        // owner pivot seedMerchant() already wrote.
        $merchantOne->users()->syncWithoutDetaching([
            $users['cashier@gasa.test']->id => ['role_in_merchant' => 'cashier'],
        ]);

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
            // Catalogs first: the demo orders below snapshot their lines
            // from real products, so the menu has to exist before them.
            $this->call(ProductSeeder::class);

            $this->seedCatalogAndOrders($merchantOne, $users['merchant@gasa.test']);
            $this->seedCatalogAndOrders($merchantTwo, $users['merchant2@gasa.test']);

            // P4: cash-session history so the reconciliation screens have
            // live and closed examples to demo against — after orders,
            // since Merchant One's open session below attributes a fresh
            // sale to itself. The default register itself no longer needs
            // seeding here: P7's Merchant::booted() guarantees every
            // merchant gets one ("Front Counter") the moment it's
            // created, above.
            $registerOne = Register::query()
                ->where('merchant_id', $merchantOne->getKey())
                ->where('name', 'Front Counter')
                ->firstOrFail();

            // Only Merchant One demos the closed/confirmed history: doing
            // it needs a SECOND user to confirm the remittance, and
            // Merchant Two has only its single owner seeded above.
            $this->seedClosedCashSession($registerOne, $users['merchant@gasa.test'], $users['cashier@gasa.test']);

            $this->seedOpenCashSession($registerOne, $users['merchant@gasa.test']);
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
     * A realistic spread of orders for one merchant, priced off the menu
     * ProductSeeder just laid down.
     *
     * The states are chosen so a frontend has something to render for
     * every branch it has to handle: an open ticket (pending), a closed
     * sale (completed), a reversal (voided), and all three payment
     * methods including a split whose parts sum to the total.
     */
    private function seedCatalogAndOrders(Merchant $merchant, User $cashier): void
    {
        // Only the sellable ones: an order priced off an unavailable
        // product would be a fixture the checkout endpoint could never
        // have produced.
        $products = Product::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('is_available', true)
            ->get();

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
     * A closed, reconciled session with a float, a cash_in, a cash_out,
     * and a remittance CONFIRMED by a second user — the full lifecycle a
     * frontend needs to render every field of the session payload against
     * something real, including the fields (confirmed_by_user_id,
     * variance_cents) that only ever appear once something has happened.
     *
     * Idempotent the same way orders are: sessions aren't updateOrCreate-
     * able (opening one is a real event with its own id), so re-running
     * skips a register that already has one.
     */
    private function seedClosedCashSession(Register $register, User $opener, User $confirmer): void
    {
        if ($register->cashSessions()->exists()) {
            return;
        }

        $session = CashSession::factory()
            ->forMerchant($register->merchant, $register, $opener)
            ->open()
            ->create(['opening_float_cents' => 500000]); // ₱5,000.00

        $session->movements()->create([
            'merchant_id' => $register->merchant_id,
            'type' => CashMovementType::CashIn,
            'amount_cents' => 100000, // ₱1,000.00 change fund top-up
            'reason' => 'Change fund top-up',
            'created_by_user_id' => $opener->getKey(),
        ]);

        $session->movements()->create([
            'merchant_id' => $register->merchant_id,
            'type' => CashMovementType::CashOut,
            'amount_cents' => 25000, // ₱250.00
            'reason' => 'Petty cash for cleaning supplies',
            'created_by_user_id' => $opener->getKey(),
        ]);

        $remittance = $session->remittances()->create([
            'merchant_id' => $register->merchant_id,
            'amount_cents' => 300000, // ₱3,000.00 bank drop
            'note' => 'End-of-day bank drop',
            'created_by_user_id' => $opener->getKey(),
        ]);

        // Through the real Action, not a direct assignment: $confirmer is
        // a genuine second user here (the seeded cashier), so this
        // exercises the same segregation-of-duties path production does,
        // matching how OrderFactory issues numbers through
        // GenerateOrderNumberAction rather than inventing its own.
        app(ConfirmRemittanceAction::class)->execute($remittance, $confirmer);

        // Counted a small amount SHORT of expected, so the demo shows a
        // non-zero variance — an exact match everywhere would hide the
        // one field (variance_cents) this whole feature exists to compute.
        $expectedCashCents = app(ReconcileCashSessionAction::class)->expectedCashCents($session);

        app(CloseCashSessionAction::class)->execute(
            $session,
            ['counted_cash_cents' => $expectedCashCents - 5000], // ₱50.00 short
            $opener,
        );
    }

    /**
     * A currently-open session for the frontend to poll against — the
     * only state that shows a LIVE (not yet snapshotted) reconciliation
     * figure. Skipped if the register already has an open one, matching
     * the partial-unique-index invariant this whole domain is built on.
     */
    private function seedOpenCashSession(Register $register, User $opener): void
    {
        if ($register->cashSessions()->where('status', 'open')->exists()) {
            return;
        }

        CashSession::factory()
            ->forMerchant($register->merchant, $register, $opener)
            ->open()
            ->create(['opening_float_cents' => 500000]);
    }
}
