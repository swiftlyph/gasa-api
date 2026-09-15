<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderBeneficiary;
use App\Domains\Orders\Support\StatutoryTax;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Senior-citizen / PWD statutory discounts and the VAT decomposition
 * (P10). See README § Tax & statutory discounts for the rules.
 *
 * EVERY FIGURE IN THIS FILE IS COMPUTED BY HAND in the comment above the
 * test that asserts it — never by re-running the code under test. This is
 * the endpoint that decides what a customer is charged and what the shop
 * owes BIR; an expectation derived from the implementation would pass
 * against any arithmetic, correct or not.
 *
 * The standing fixture, used by most tests below:
 *
 *   Latte      ₱140.00  = 14000 cents (VAT-inclusive on a VAT shop)
 *   Cold Brew  ₱185.00  = 18500 cents
 *
 * At 12% VAT: 14000 / 1.12 = 12500 exactly.
 *             18500 / 1.12 = 16517.857… → 16518 (half-up).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->token = fn () => $this->user->createToken('merchant')->plainTextToken;

    // Each test picks its own merchant's VAT status, so the merchant is
    // built per-test rather than here.
    $this->makeShop = function (bool $vatRegistered): Merchant {
        $merchant = Merchant::factory()->ownedBy($this->user)->create([
            'name' => 'Merchant One',
            'vat_registered' => $vatRegistered,
        ]);

        $this->latte = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Cafe Latte (16oz)',
            'price_cents' => 14000,
        ]);

        $this->brew = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Cold Brew (22oz)',
            'price_cents' => 18500,
        ]);

        return $merchant;
    };

    $this->checkout = fn (array $payload, ?string $key = null) => $this
        ->withToken($this->user->createToken('merchant')->plainTextToken)
        ->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])
        ->postJson('/api/v1/merchant/orders', $payload);
});

/*
|--------------------------------------------------------------------------
| VAT-registered merchant — the full group order
|--------------------------------------------------------------------------
|
| BY HAND, at 12% VAT and a 20% statutory discount:
|
|   Latte for the senior:  line 14000
|                          net  = round(14000 / 1.12) = 12500  (exact)
|                          disc = round(12500 × 20%)  =  2500  (exact)
|                          pay  = 12500 − 2500        = 10000
|   Brew for the senior:   line 18500
|                          net  = round(18500 / 1.12) = 16518  (16517.857 up)
|                          disc = round(16518 × 20%)  =  3304  (3303.6 up)
|                          pay  = 16518 − 3304        = 13214
|   Latte for nobody:      line 14000
|                          vatable = 12500, vat = 14000 − 12500 = 1500
|                          pay     = 14000  (the VAT stays in the price)
|
|   subtotal                 = 14000 + 18500 + 14000       = 46500
|   vat_exempt_sales         = 12500 + 16518               = 29018
|   vatable_sales            = 12500
|   vat                      = 1500
|   nonvat_sales             = 0
|   statutory (TOTAL RELIEF) = (14000−10000) + (18500−13214) = 9286
|   promo                    = 1000
|   discount                 = 9286 + 1000                 = 10286
|   total                    = 46500 − 10286               = 36214
|   Σ payable                = 10000 + 13214 + 14000       = 37214
|   Σ payable − promo        = 37214 − 1000                = 36214  ✓ agrees
|
|   The beneficiary's OWN printed discount is the 20% alone:
|                              2500 + 3304                 =  5804
*/
test('a VAT-registered group order matches every hand-computed figure', function () {
    ($this->makeShop)(vatRegistered: true);

    $response = ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'beneficiaries' => [
            ['type' => 'senior', 'name' => 'Lola Remedios', 'id_number' => 'SC-2020-0001'],
        ],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->brew->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->latte->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    $response
        ->assertJsonPath('subtotal_cents', 46500)
        ->assertJsonPath('discount_cents', 10286)
        ->assertJsonPath('total_cents', 36214)
        ->assertJsonPath('total_formatted', '₱362.14')

        ->assertJsonPath('tax.vat_registered', true)
        ->assertJsonPath('tax.vat_rate_bps', 1200)
        ->assertJsonPath('tax.vatable_sales_cents', 12500)
        ->assertJsonPath('tax.vat_cents', 1500)
        ->assertJsonPath('tax.vat_exempt_sales_cents', 29018)
        ->assertJsonPath('tax.nonvat_sales_cents', 0)
        ->assertJsonPath('tax.statutory_discount_cents', 9286)
        ->assertJsonPath('tax.promo_discount_cents', 1000)

        // The beneficiary's own figures: the 20% they actually saved.
        ->assertJsonCount(1, 'beneficiaries')
        ->assertJsonPath('beneficiaries.0.type', 'senior')
        ->assertJsonPath('beneficiaries.0.name', 'Lola Remedios')
        ->assertJsonPath('beneficiaries.0.id_number', 'SC-2020-0001')
        ->assertJsonPath('beneficiaries.0.discount_cents', 5804)
        ->assertJsonPath('beneficiaries.0.vat_exempt_sales_cents', 29018)

        // Per line.
        ->assertJsonPath('items.0.line_total_cents', 14000)
        ->assertJsonPath('items.0.discount_cents', 2500)
        ->assertJsonPath('items.0.payable_cents', 10000)
        ->assertJsonPath('items.1.line_total_cents', 18500)
        ->assertJsonPath('items.1.discount_cents', 3304)
        ->assertJsonPath('items.1.payable_cents', 13214)
        ->assertJsonPath('items.2.line_total_cents', 14000)
        ->assertJsonPath('items.2.discount_cents', 0)
        ->assertJsonPath('items.2.payable_cents', 14000);

    // And the same figures in the database, not just the response.
    $order = Order::query()->with(['items', 'beneficiaries'])->sole();

    expect($order->subtotal_cents)->toBe(46500)
        ->and($order->statutory_discount_cents)->toBe(9286)
        ->and($order->promo_discount_cents)->toBe(1000)
        ->and($order->discount_cents)->toBe(10286)
        ->and($order->total_cents)->toBe(36214)
        ->and($order->vat_exempt_sales_cents)->toBe(29018)
        // 12500 + 16518 + 12500 — every line's net, hand-computed above.
        ->and($order->items->sum('net_of_vat_cents'))->toBe(41518);

    // The two invariants the whole phase rests on.
    expect($order->total_cents)->toBe($order->subtotal_cents - $order->discount_cents)
        ->and($order->total_cents)->toBe($order->items->sum('payable_cents') - $order->promo_discount_cents)
        ->and($order->discount_cents)->toBe($order->statutory_discount_cents + $order->promo_discount_cents);

    // Every line assigned to the senior points at the stored row.
    $beneficiary = $order->beneficiaries->sole();
    expect($order->items->where('beneficiary_id', $beneficiary->id))->toHaveCount(2)
        ->and($order->items->whereNull('beneficiary_id'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Non-VAT merchant — the same basket
|--------------------------------------------------------------------------
|
| BY HAND. No VAT was ever in the price, so the 20% comes straight off the
| line total and every VAT field is zero:
|
|   Latte (PWD):  disc = round(14000 × 20%) = 2800 → pay 11200
|   Brew  (PWD):  disc = round(18500 × 20%) = 3700 → pay 14800
|   Latte (none): pay 14000
|
|   subtotal      = 46500
|   nonvat_sales  = 46500          (every line, pre-discount)
|   statutory     = 2800 + 3700    =  6500
|   promo         = 1000
|   discount      = 7500
|   total         = 46500 − 7500   = 39000
|   Σ payable     = 11200 + 14800 + 14000 = 40000
|   Σ pay − promo = 39000          ✓ agrees
*/
test('a non-VAT merchant takes 20% straight off, with every VAT field zero', function () {
    ($this->makeShop)(vatRegistered: false);

    $response = ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'beneficiaries' => [
            ['type' => 'pwd', 'name' => 'Juan Cruz', 'id_number' => 'PWD-1234-5678'],
        ],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->brew->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->latte->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    $response
        ->assertJsonPath('subtotal_cents', 46500)
        ->assertJsonPath('discount_cents', 7500)
        ->assertJsonPath('total_cents', 39000)

        ->assertJsonPath('tax.vat_registered', false)
        ->assertJsonPath('tax.vat_rate_bps', 0)
        ->assertJsonPath('tax.vatable_sales_cents', 0)
        ->assertJsonPath('tax.vat_cents', 0)
        ->assertJsonPath('tax.vat_exempt_sales_cents', 0)
        ->assertJsonPath('tax.nonvat_sales_cents', 46500)
        ->assertJsonPath('tax.statutory_discount_cents', 6500)
        ->assertJsonPath('tax.promo_discount_cents', 1000)

        ->assertJsonPath('beneficiaries.0.type', 'pwd')
        ->assertJsonPath('beneficiaries.0.discount_cents', 6500)
        // Nothing was exempted from a VAT that never existed.
        ->assertJsonPath('beneficiaries.0.vat_exempt_sales_cents', 0)

        ->assertJsonPath('items.0.discount_cents', 2800)
        ->assertJsonPath('items.0.payable_cents', 11200)
        ->assertJsonPath('items.1.discount_cents', 3700)
        ->assertJsonPath('items.1.payable_cents', 14800)
        ->assertJsonPath('items.2.discount_cents', 0)
        ->assertJsonPath('items.2.payable_cents', 14000);

    $order = Order::query()->with('items')->sole();

    expect($order->total_cents)->toBe($order->subtotal_cents - $order->discount_cents)
        ->and($order->total_cents)->toBe($order->items->sum('payable_cents') - $order->promo_discount_cents)
        // On a non-VAT line the "net of VAT" is the full amount, not zero
        // — see the order_items migration.
        ->and($order->items->sum('net_of_vat_cents'))->toBe(46500);
});

/*
|--------------------------------------------------------------------------
| Two beneficiaries on one order
|--------------------------------------------------------------------------
|
| A senior and a PWD eating together, each with their OWN line, on a
| VAT-registered shop. BY HAND:
|
|   senior: latte 14000 → net 12500, disc 2500, pay 10000
|   pwd:    brew  18500 → net 16518, disc 3304, pay 13214
|   nobody: latte 14000 → vatable 12500, vat 1500, pay 14000
|
|   Each beneficiary's discount is THEIR OWN line's, never the other's.
|   statutory = (14000−10000) + (18500−13214) = 9286
|   total     = 46500 − 9286 = 37214   (no promo this time)
*/
test('two beneficiaries get separate rows and separate discounts', function () {
    ($this->makeShop)(vatRegistered: true);

    $response = ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [
            ['type' => 'senior', 'name' => 'Lola Remedios', 'id_number' => 'SC-2020-0001'],
            ['type' => 'pwd', 'name' => 'Juan Cruz', 'id_number' => 'PWD-1234-5678'],
        ],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->brew->id, 'quantity' => 1, 'beneficiary' => 1],
            ['product_id' => $this->latte->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    $response
        ->assertJsonCount(2, 'beneficiaries')
        ->assertJsonPath('beneficiaries.0.type', 'senior')
        ->assertJsonPath('beneficiaries.0.name', 'Lola Remedios')
        ->assertJsonPath('beneficiaries.0.discount_cents', 2500)
        ->assertJsonPath('beneficiaries.0.vat_exempt_sales_cents', 12500)
        ->assertJsonPath('beneficiaries.1.type', 'pwd')
        ->assertJsonPath('beneficiaries.1.name', 'Juan Cruz')
        ->assertJsonPath('beneficiaries.1.discount_cents', 3304)
        ->assertJsonPath('beneficiaries.1.vat_exempt_sales_cents', 16518)
        ->assertJsonPath('tax.statutory_discount_cents', 9286)
        ->assertJsonPath('total_cents', 37214);

    $order = Order::query()->with(['items', 'beneficiaries'])->sole();
    [$senior, $pwd] = $order->beneficiaries->all();

    // Each line points at its own person, and neither discount leaked
    // into the other.
    expect($order->items->where('beneficiary_id', $senior->id)->pluck('discount_cents')->all())->toBe([2500])
        ->and($order->items->where('beneficiary_id', $pwd->id)->pluck('discount_cents')->all())->toBe([3304])
        ->and($order->total_cents)->toBe($order->items->sum('payable_cents'));
});

test('a beneficiary line still charges other lines in full', function () {
    // The group-order rule stated on its own: only the senior's OWN
    // consumption is discounted, never the table's.
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->latte->id, 'quantity' => 3],
        ],
    ])->assertCreated()
        // 14000 × 20% = 2800 off the senior's single latte, and the other
        // three lattes (42000) are untouched.
        ->assertJsonPath('items.0.payable_cents', 11200)
        ->assertJsonPath('items.1.line_total_cents', 42000)
        ->assertJsonPath('items.1.discount_cents', 0)
        ->assertJsonPath('items.1.payable_cents', 42000)
        ->assertJsonPath('subtotal_cents', 56000)
        ->assertJsonPath('discount_cents', 2800)
        ->assertJsonPath('total_cents', 53200);
});

/*
|--------------------------------------------------------------------------
| The snapshot rule
|--------------------------------------------------------------------------
*/
test('toggling vat_registered afterwards never changes a stored order or its slip', function () {
    $merchant = ($this->makeShop)(vatRegistered: true);

    $orderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('tax.vat_registered', true)
        ->assertJsonPath('tax.vat_cents', 1500)
        ->json('id');

    // The shop deregisters the next day.
    $merchant->update(['vat_registered' => false]);

    $this->withToken($this->user->createToken('m')->plainTextToken)
        ->getJson("/api/v1/merchant/orders/{$orderId}")
        ->assertOk()
        // Still a VAT sale, because it WAS one.
        ->assertJsonPath('tax.vat_registered', true)
        ->assertJsonPath('tax.vat_rate_bps', 1200)
        ->assertJsonPath('tax.vatable_sales_cents', 12500)
        ->assertJsonPath('tax.vat_cents', 1500);

    // And the reprinted slip agrees.
    $this->withToken($this->user->createToken('m')->plainTextToken)
        ->getJson("/api/v1/merchant/orders/{$orderId}/receipt")
        ->assertOk()
        ->assertJsonPath('order.tax.vat_registered', true)
        ->assertJsonPath('order.tax.vat_cents', 1500)
        ->assertJsonMissingPath('order.tax.non_vat_note');

    expect(Order::query()->sole()->vat_registered_snapshot)->toBeTrue();
});

test('a rate change in config never re-taxes an order that already happened', function () {
    ($this->makeShop)(vatRegistered: true);

    $orderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');

    // An accountant corrects the rate. History must not move.
    config(['merchant.vat_rate_bps' => 1000]);

    $this->withToken($this->user->createToken('m')->plainTextToken)
        ->getJson("/api/v1/merchant/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('tax.vat_rate_bps', 1200)
        ->assertJsonPath('tax.vat_cents', 1500);
});

/*
|--------------------------------------------------------------------------
| Rejections
|--------------------------------------------------------------------------
*/
test('a beneficiary with no lines is a 422 beneficiary_unused naming the index', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [
            ['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1'],
            ['type' => 'pwd', 'name' => 'Juan', 'id_number' => 'PWD-2'],
        ],
        'items' => [
            // Only the first beneficiary is claimed.
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'beneficiary_unused')
        ->assertJsonPath('errors.beneficiaries', ['1']);

    // Nothing was sold: rejected before anything is written.
    expect(Order::query()->count())->toBe(0)
        ->and(DB::table('order_beneficiaries')->count())->toBe(0);
});

test('a missing id_number or name is a 422 validation_failed', function (string $missing) {
    ($this->makeShop)(vatRegistered: false);

    $beneficiary = ['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1'];
    unset($beneficiary[$missing]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [$beneficiary],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['beneficiaries.0.'.$missing]]);

    expect(Order::query()->count())->toBe(0);
})->with(['name', 'id_number']);

test('a blank id_number is rejected as firmly as a missing one', function () {
    // "The cashier didn't bother" must not be storable as an ID.
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => '   ']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

test('an unknown beneficiary type is rejected', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'student', 'name' => 'Ana', 'id_number' => 'ST-1']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['beneficiaries.0.type']]);
});

test('a line naming a beneficiary index that was never declared is rejected', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            // There is no beneficiary 5.
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 5],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['items.1.beneficiary']]);

    expect(Order::query()->count())->toBe(0);
});

test('a line claiming a beneficiary when none were declared is rejected', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

/*
|--------------------------------------------------------------------------
| The promo cap is post-statutory
|--------------------------------------------------------------------------
|
| BY HAND, non-VAT shop, one latte for a senior:
|   line 14000, statutory 2800, payable 11200.
| A promo of 11200 is exactly the whole remainder (allowed, totals zero);
| 11201 is one centavo more than exists (rejected). Note that 14000 —
| the old, pre-statutory subtotal — is now REJECTED, which is the point.
*/
test('a promo discount larger than the POST-statutory subtotal is a 422', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 11201,
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'discount_exceeds_subtotal');

    expect(Order::query()->count())->toBe(0);
});

test('a promo equal to the post-statutory subtotal is allowed and totals zero', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 11200,
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertCreated()
        ->assertJsonPath('total_cents', 0)
        ->assertJsonPath('discount_cents', 14000)
        ->assertJsonPath('tax.statutory_discount_cents', 2800)
        ->assertJsonPath('tax.promo_discount_cents', 11200);
});

test('a promo that would have fit BEFORE the statutory discount is now rejected', function () {
    // 14000 was the whole subtotal pre-P10 and would once have been a
    // legal full comp. After a 2800 statutory discount only 11200 remains.
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 14000,
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'discount_exceeds_subtotal');
});

/*
|--------------------------------------------------------------------------
| Backwards compatibility
|--------------------------------------------------------------------------
*/
test('an order with no beneficiaries is byte-for-byte what it was before P10', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 5000,
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 2],
            ['product_id' => $this->brew->id, 'quantity' => 1],
        ],
    ])->assertCreated()
        // 28000 + 18500 = 46500, less a ₱50 manual discount.
        ->assertJsonPath('subtotal_cents', 46500)
        ->assertJsonPath('discount_cents', 5000)
        ->assertJsonPath('total_cents', 41500)
        // The discount is entirely promo; no statutory discount happened.
        ->assertJsonPath('tax.statutory_discount_cents', 0)
        ->assertJsonPath('tax.promo_discount_cents', 5000)
        ->assertJsonCount(0, 'beneficiaries')
        ->assertJsonPath('items.0.discount_cents', 0)
        ->assertJsonPath('items.0.payable_cents', 28000);

    $order = Order::query()->sole();

    expect($order->statutory_discount_cents)->toBe(0)
        ->and($order->promo_discount_cents)->toBe(5000)
        ->and($order->discount_cents)->toBe(5000)
        ->and($order->vat_registered_snapshot)->toBeFalse();
});

test('a VAT merchant with no beneficiaries still decomposes VAT and charges the same total', function () {
    ($this->makeShop)(vatRegistered: true);

    // BY HAND: 2 lattes = 28000 incl VAT. vatable = round(28000/1.12)
    // = 25000 exactly; vat = 3000. The customer still pays 28000 — the
    // VAT decomposition never changes what is charged.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 2]],
    ])->assertCreated()
        ->assertJsonPath('subtotal_cents', 28000)
        ->assertJsonPath('discount_cents', 0)
        ->assertJsonPath('total_cents', 28000)
        ->assertJsonPath('tax.vatable_sales_cents', 25000)
        ->assertJsonPath('tax.vat_cents', 3000)
        ->assertJsonPath('tax.vat_exempt_sales_cents', 0)
        ->assertJsonPath('tax.statutory_discount_cents', 0)
        ->assertJsonPath('items.0.payable_cents', 28000);
});

/*
|--------------------------------------------------------------------------
| The rounding rule
|--------------------------------------------------------------------------
*/
test('rounding is half-up at each documented step', function () {
    // Hand-checked values, independent of any call into the app:
    //   14000 / 1.12 = 12500        exactly           -> 12500
    //   10000 / 1.12 =  8928.571…   rounds up         ->  8929
    //   18500 / 1.12 = 16517.857…   rounds up         -> 16518
    //   20% of 8929  =  1785.8      rounds up         ->  1786
    //   50% of 5     =     2.5      exact tie, UP     ->     3
    //   20% of 2     =     0.4      rounds down       ->     0
    expect(StatutoryTax::netOfVat(14000, 1200))->toBe(12500)
        ->and(StatutoryTax::netOfVat(10000, 1200))->toBe(8929)
        ->and(StatutoryTax::netOfVat(18500, 1200))->toBe(16518)
        ->and(StatutoryTax::percentageOf(8929, 2000))->toBe(1786)
        ->and(StatutoryTax::percentageOf(5, 5000))->toBe(3)
        ->and(StatutoryTax::percentageOf(2, 2000))->toBe(0);
});

test('net plus VAT always reconstitutes the gross exactly', function () {
    // The property that keeps a receipt's own lines adding up to what was
    // paid: VAT is derived by subtraction, never rounded separately.
    foreach ([1, 2, 3, 99, 100, 14000, 18500, 12345, 99999] as $gross) {
        expect(StatutoryTax::netOfVat($gross, 1200) + StatutoryTax::vatOn($gross, 1200))
            ->toBe($gross);
    }
});

test('the rates come from config, not from literals in the code', function () {
    config(['merchant.vat_rate_bps' => 1000, 'merchant.statutory_discount_bps' => 2500]);

    expect(StatutoryTax::vatRateBps())->toBe(1000)
        ->and(StatutoryTax::statutoryDiscountBps())->toBe(2500)
        // 11000 at 10% VAT: 11000 / 1.10 = 10000 exactly.
        ->and(StatutoryTax::netOfVat(11000, 1000))->toBe(10000);
});

test('a corrected config rate is used by the next sale', function () {
    ($this->makeShop)(vatRegistered: true);

    config(['merchant.vat_rate_bps' => 1000]);

    // BY HAND at 10%: round(14000 / 1.10) = round(12727.27) = 12727,
    // vat = 14000 − 12727 = 1273.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('tax.vat_rate_bps', 1000)
        ->assertJsonPath('tax.vatable_sales_cents', 12727)
        ->assertJsonPath('tax.vat_cents', 1273);
});

/*
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
*/
test('the beneficiary row records what was presented at the counter', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola Remedios', 'id_number' => 'SC-2020-0001']],
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertCreated();

    $beneficiary = OrderBeneficiary::query()->sole();

    expect($beneficiary->name)->toBe('Lola Remedios')
        ->and($beneficiary->id_number)->toBe('SC-2020-0001')
        ->and($beneficiary->type->value)->toBe('senior')
        ->and($beneficiary->discount_cents)->toBe(2800)
        ->and($beneficiary->order_id)->toBe(Order::query()->sole()->id);
});

test('a failed checkout writes no beneficiary rows either', function () {
    ($this->makeShop)(vatRegistered: false);

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1, 'beneficiary' => 0],
            // Rejects the whole basket, after the beneficiary would have
            // been written if it were written outside the transaction.
            ['product_id' => 999999, 'quantity' => 1],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    expect(Order::query()->count())->toBe(0)
        ->and(DB::table('order_beneficiaries')->count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0);
});
