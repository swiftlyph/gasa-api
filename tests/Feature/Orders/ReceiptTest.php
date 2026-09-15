<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * GET /merchant/orders/{order}/receipt (P9) — printable receipt data.
 * Composes the merchant's CURRENT profile with the order's OWN stored
 * snapshots — see ReceiptResource's docblock for the snapshot-discipline
 * rule this file proves.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create(['name' => 'Alice Cashier']);
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create([
        'name' => 'Merchant One',
        'legal_name' => 'Merchant One Food Corp.',
        'address_line1' => '123 Rizal St',
        'address_line2' => 'Unit 4',
        'city' => 'Cebu City',
        'postal_code' => '6000',
        'phone' => '+63 917 000 0000',
        'tax_identifier' => '123-456-789-000',
        'receipt_header' => 'Thank you for visiting!',
        'receipt_footer' => 'No refunds after 24 hours.',
    ]);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->receipt = fn (int $orderId) => $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$orderId}/receipt");
});

test('a normal order\'s receipt carries the profile header/footer and every line', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->cash()
        ->create([
            'order_number' => 'ORD-000123',
            'subtotal_cents' => 24000,
            'discount_cents' => 2000,
            'total_cents' => 22000,
        ]);

    $item = OrderItem::factory()->for($order)->create([
        'product_name' => 'Cafe Latte (16oz)',
        'quantity' => 2,
        'unit_price_cents' => 12000,
        'line_total_cents' => 24000,
    ]);
    $item->addOns()->create(['name' => 'Extra shot', 'price_cents' => 3000]);

    $response = ($this->receipt)($order->id)->assertOk();

    $response
        ->assertJsonPath('merchant.name', 'Merchant One')
        ->assertJsonPath('merchant.legal_name', 'Merchant One Food Corp.')
        ->assertJsonPath('merchant.address_line1', '123 Rizal St')
        ->assertJsonPath('merchant.address_line2', 'Unit 4')
        ->assertJsonPath('merchant.city', 'Cebu City')
        ->assertJsonPath('merchant.postal_code', '6000')
        ->assertJsonPath('merchant.phone', '+63 917 000 0000')
        ->assertJsonPath('merchant.tax_identifier', '123-456-789-000')
        ->assertJsonPath('merchant.receipt_header', 'Thank you for visiting!')
        ->assertJsonPath('merchant.receipt_footer', 'No refunds after 24 hours.')

        ->assertJsonPath('order.order_number', 'ORD-000123')
        ->assertJsonPath('order.status', 'completed')
        ->assertJsonPath('order.voided', false)
        ->assertJsonPath('order.voided_at', null)
        ->assertJsonPath('order.cashier_name', 'Alice Cashier')

        ->assertJsonPath('order.lines.0.product_name', 'Cafe Latte (16oz)')
        ->assertJsonPath('order.lines.0.quantity', 2)
        ->assertJsonPath('order.lines.0.unit_price_cents', 12000)
        ->assertJsonPath('order.lines.0.unit_price_formatted', '₱120.00')
        ->assertJsonPath('order.lines.0.line_total_cents', 24000)
        ->assertJsonPath('order.lines.0.add_ons.0.name', 'Extra shot')
        ->assertJsonPath('order.lines.0.add_ons.0.price_cents', 3000)

        ->assertJsonPath('order.subtotal_cents', 24000)
        ->assertJsonPath('order.discount_cents', 2000)
        ->assertJsonPath('order.total_cents', 22000)
        ->assertJsonPath('order.total_formatted', '₱220.00')
        ->assertJsonPath('order.payment_method', 'cash')
        ->assertJsonPath('order.cash_cents', null)
        ->assertJsonPath('order.gcash_cents', null);
});

test('a voided order\'s receipt is 200, never 404, and carries voided=true with voided_at', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->voided($this->user)
        ->create(['total_cents' => 5000]);

    $response = ($this->receipt)($order->id)->assertOk();

    $response
        ->assertJsonPath('order.status', 'voided')
        ->assertJsonPath('order.voided', true);

    expect($response->json('order.voided_at'))->not->toBeNull();
});

test('a split order\'s receipt shows both the cash and gcash portions', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->split()
        ->create(['subtotal_cents' => 10000, 'discount_cents' => 0, 'total_cents' => 10000, 'cash_cents' => 4000, 'gcash_cents' => 6000]);

    $response = ($this->receipt)($order->id)->assertOk();

    $response
        ->assertJsonPath('order.payment_method', 'split')
        ->assertJsonPath('order.cash_cents', 4000)
        ->assertJsonPath('order.cash_formatted', '₱40.00')
        ->assertJsonPath('order.gcash_cents', 6000)
        ->assertJsonPath('order.gcash_formatted', '₱60.00');
});

test('a profile change after the sale shows the CURRENT header on reprint, not a snapshot', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();

    ($this->receipt)($order->id)->assertOk()
        ->assertJsonPath('merchant.receipt_header', 'Thank you for visiting!');

    $this->merchant->update(['receipt_header' => 'New header after the sale']);

    ($this->receipt)($order->id)->assertOk()
        ->assertJsonPath('merchant.receipt_header', 'New header after the sale');
});

test('renaming the product after the sale leaves the receipt line unchanged — it reads the snapshot', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Original Name']);

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();
    OrderItem::factory()->for($order)->forProduct($product)->create(['product_name' => 'Original Name']);

    $product->update(['name' => 'Renamed Later']);

    ($this->receipt)($order->id)->assertOk()
        ->assertJsonPath('order.lines.0.product_name', 'Original Name');
});

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/
test('the receipt endpoint is gated by OrderPolicy::view — same permission_denied wiring show() already proves', function () {
    // Every current role_in_merchant preset (owner/manager/staff) carries
    // orders.view (see RolePresets), so there is no real role in today's
    // catalog that reaches this endpoint's authorize() call and is denied
    // by it — the same is already true of GET /orders/{order}. What IS
    // worth proving here is that receipt() calls authorize('view', ...)
    // at all, i.e. it is not accidentally unguarded: an authenticated
    // user with no active merchant fails one layer earlier
    // (merchant_inactive, OrderPolicy::ownsOrder's first check), and a
    // cross-tenant id fails at the route-model-binding/global-scope layer
    // before the policy even runs (proven below and in TenantLeakageTest)
    // — see PermissionsTest.php's staff/reports.view cases for the
    // pattern this file would extend the moment a role without
    // orders.view exists.
    $outsider = User::factory()->withRole('merchant')->create();
    $token = $outsider->createToken('merchant')->plainTextToken;

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();

    $this->withToken($token)
        ->getJson("/api/v1/merchant/orders/{$order->id}/receipt")
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);
});

test('merchant two cannot read merchant one\'s receipt — 404', function () {
    $userTwo = User::factory()->withRole('merchant')->create();
    Merchant::factory()->ownedBy($userTwo)->create(['name' => 'Merchant Two']);
    $tokenTwo = $userTwo->createToken('merchant')->plainTextToken;

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();

    $this->withToken($tokenTwo)
        ->getJson("/api/v1/merchant/orders/{$order->id}/receipt")
        ->assertStatus(404);
});

test('a suspended merchant gets 403 merchant_inactive on the receipt endpoint', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();

    $this->merchant->update(['status' => 'suspended']);

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}/receipt")
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);
});

/*
|--------------------------------------------------------------------------
| Read-only and query-bounded
|--------------------------------------------------------------------------
*/
test('the receipt endpoint writes nothing of its own', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();
    OrderItem::factory()->for($order)->withAddOns(2)->create();

    $writes = collect();
    DB::listen(function ($query) use ($writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes->push($query->sql);
        }
    });

    ($this->receipt)($order->id)->assertOk();

    expect($writes->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens')))
        ->toBeEmpty();
});

test('the receipt endpoint runs a bounded, small number of queries — no N+1', function () {
    $countQueries = function (int $lineCount): int {
        $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->create();

        for ($i = 0; $i < $lineCount; $i++) {
            OrderItem::factory()->for($order)->withAddOns(2)->create(['product_name' => "Product {$i}"]);
        }

        ($this->receipt)($order->id)->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        ($this->receipt)($order->id)->assertOk();

        return $queries;
    };

    $small = $countQueries(2);
    $large = $countQueries(20);

    expect($large)->toBe($small);
});

/*
|--------------------------------------------------------------------------
| The statutory block (P10)
|--------------------------------------------------------------------------
|
| The slip has to print who claimed a discount, the ID they presented, and
| what it saved them — and it has to state the VAT position rather than
| leave it to be inferred from zeroes.
*/

test('a VAT-registered order prints the VAT block and every beneficiary', function () {
    $this->merchant->update(['vat_registered' => true]);

    $latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    // BY HAND, 12% VAT / 20% discount, one latte for a senior and one for
    // nobody:
    //   senior line: net = 14000/1.12 = 12500, disc = 2500, pay = 10000
    //   other line:  vatable 12500, vat 1500, pay 14000
    //   subtotal 28000; statutory relief = 14000 − 10000 = 4000
    //   total = 28000 − 4000 = 24000
    $orderId = $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'beneficiaries' => [['type' => 'senior', 'name' => 'Lola Remedios', 'id_number' => 'SC-2020-0001']],
            'items' => [
                ['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0],
                ['product_id' => $latte->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('id');

    ($this->receipt)($orderId)->assertOk()
        ->assertJsonPath('order.tax.vat_registered', true)
        ->assertJsonPath('order.tax.vat_rate_bps', 1200)
        ->assertJsonPath('order.tax.vatable_sales_cents', 12500)
        ->assertJsonPath('order.tax.vat_cents', 1500)
        ->assertJsonPath('order.tax.vat_formatted', '₱15.00')
        ->assertJsonPath('order.tax.vat_exempt_sales_cents', 12500)
        // A VAT slip must NOT carry the non-VAT note.
        ->assertJsonMissingPath('order.tax.non_vat_note')

        ->assertJsonCount(1, 'order.beneficiaries')
        ->assertJsonPath('order.beneficiaries.0.type', 'senior')
        ->assertJsonPath('order.beneficiaries.0.type_label', 'Senior Citizen')
        ->assertJsonPath('order.beneficiaries.0.name', 'Lola Remedios')
        ->assertJsonPath('order.beneficiaries.0.id_number', 'SC-2020-0001')
        ->assertJsonPath('order.beneficiaries.0.discount_cents', 2500)
        ->assertJsonPath('order.beneficiaries.0.discount_formatted', '₱25.00')

        ->assertJsonPath('order.statutory_discount_cents', 4000)
        ->assertJsonPath('order.promo_discount_cents', 0)
        ->assertJsonPath('order.total_cents', 24000)

        // Per line, so a slip can show what each drink actually cost.
        ->assertJsonPath('order.lines.0.discount_cents', 2500)
        ->assertJsonPath('order.lines.0.payable_cents', 10000)
        ->assertJsonPath('order.lines.1.discount_cents', 0)
        ->assertJsonPath('order.lines.1.payable_cents', 14000);
});

test('a non-VAT order prints the Non-VAT note and no VAT lines', function () {
    // The merchant fixture is non-VAT by default (the column's default).
    $latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    // BY HAND: 14000 × 20% = 2800 off the senior's latte.
    $orderId = $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'beneficiaries' => [['type' => 'pwd', 'name' => 'Juan Cruz', 'id_number' => 'PWD-1234-5678']],
            'items' => [['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0]],
        ])->assertCreated()->json('id');

    ($this->receipt)($orderId)->assertOk()
        ->assertJsonPath('order.tax.vat_registered', false)
        ->assertJsonPath('order.tax.non_vat_note', 'This is a NON-VAT registered sale.')
        ->assertJsonPath('order.tax.nonvat_sales_cents', 14000)
        // No VAT lines at all on a slip for a shop that charges none.
        ->assertJsonMissingPath('order.tax.vat_cents')
        ->assertJsonMissingPath('order.tax.vatable_sales_cents')
        ->assertJsonMissingPath('order.tax.vat_exempt_sales_cents')

        ->assertJsonPath('order.beneficiaries.0.type', 'pwd')
        ->assertJsonPath('order.beneficiaries.0.type_label', 'Person with Disability')
        ->assertJsonPath('order.beneficiaries.0.id_number', 'PWD-1234-5678')
        ->assertJsonPath('order.beneficiaries.0.discount_cents', 2800)
        ->assertJsonPath('order.total_cents', 11200);
});

test('an ordinary order still prints an empty beneficiary list', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()->cash()
        ->create(['subtotal_cents' => 10000, 'discount_cents' => 0, 'total_cents' => 10000]);

    OrderItem::factory()->for($order)->create([
        'product_name' => 'Americano',
        'quantity' => 1,
        'unit_price_cents' => 10000,
        'line_total_cents' => 10000,
        'net_of_vat_cents' => 10000,
        'payable_cents' => 10000,
    ]);

    ($this->receipt)($order->id)->assertOk()
        ->assertJsonCount(0, 'order.beneficiaries')
        ->assertJsonPath('order.tax.vat_registered', false)
        ->assertJsonPath('order.statutory_discount_cents', 0);
});
