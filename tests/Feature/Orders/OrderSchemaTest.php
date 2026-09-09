<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Schema-level guarantees, asserted against the live database rather than
 * the PHP that usually keeps them true.
 *
 * The point is exactly that these bypass the models: the enums and the
 * snapshot rules are only as good as the columns underneath them, and a
 * constraint that exists in a migration but was never applied looks
 * identical to one that works until the day something writes around
 * Eloquent (a raw report, a data fix, a future service).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rawOrderRow(int $merchantId, int $userId, array $overrides = []): array
{
    return array_merge([
        'merchant_id' => $merchantId,
        'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
        'status' => 'pending',
        'subtotal_cents' => 10000,
        'discount_cents' => 0,
        'total_cents' => 10000,
        'currency' => 'PHP',
        'payment_method' => 'cash',
        'created_by_user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

test('the database rejects a status outside the enum', function () {
    expect(fn () => DB::table('orders')->insert(
        rawOrderRow($this->merchant->id, $this->user->id, ['status' => 'refunded']),
    ))->toThrow(QueryException::class);
});

test('the database rejects a payment method outside the enum', function () {
    expect(fn () => DB::table('orders')->insert(
        rawOrderRow($this->merchant->id, $this->user->id, ['payment_method' => 'crypto']),
    ))->toThrow(QueryException::class);
});

test('the database accepts every value the enums declare', function () {
    // The other half of the constraint test: it must reject what is not
    // in the enum AND accept everything that is, or a mismatch between
    // the PHP enum and the CHECK would show up as a production 500 on a
    // perfectly valid write.
    foreach (OrderStatus::values() as $status) {
        foreach (PaymentMethod::values() as $method) {
            DB::table('orders')->insert(rawOrderRow($this->merchant->id, $this->user->id, [
                'status' => $status,
                'payment_method' => $method,
            ]));
        }
    }

    expect(DB::table('orders')->count())->toBe(9);
});

test('an order snapshot survives the product being repriced and renamed', function () {
    $product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Spanish Latte (16oz)',
        'price_cents' => 15000,
    ]);

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->create();
    $item = OrderItem::factory()->for($order)->forProduct($product)->create(['quantity' => 2]);

    // The merchant reprices and rebrands the drink the next morning.
    $product->update(['name' => 'Signature Spanish Latte (16oz)', 'price_cents' => 18000]);

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        // Yesterday's receipt still reads yesterday's menu.
        ->assertJsonPath('items.0.product_name', 'Spanish Latte (16oz)')
        ->assertJsonPath('items.0.unit_price_cents', 15000)
        ->assertJsonPath('items.0.unit_price_formatted', '₱150.00')
        ->assertJsonPath('items.0.line_total_cents', 30000)
        // ...and the link back to the live catalog is intact.
        ->assertJsonPath('items.0.product_id', $product->id);

    expect($item->fresh()->product_name)->toBe('Spanish Latte (16oz)');
});

test('an order snapshot survives the product being deleted outright', function () {
    $product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Discontinued Cold Brew',
        'price_cents' => 18000,
    ]);

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->create();
    OrderItem::factory()->for($order)->forProduct($product)->create(['quantity' => 1]);

    $product->delete();

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        // product_id nulls out (ON DELETE SET NULL) but the line is whole.
        ->assertJsonPath('items.0.product_id', null)
        ->assertJsonPath('items.0.product_name', 'Discontinued Cold Brew')
        ->assertJsonPath('items.0.unit_price_cents', 18000)
        ->assertJsonPath('items.0.line_total_cents', 18000);

    // And the order itself is untouched — deleting a product must never
    // cascade into financial history.
    expect(Order::query()->withoutGlobalScope('merchant')->find($order->id))->not->toBeNull();
});

test('deleting an order cascades to its items and add-ons', function () {
    // Not an endpoint — there is deliberately no way to delete an order
    // over HTTP. This pins the FK behaviour for the one place it matters:
    // a merchant row being removed must not strand orphan line items.
    //
    // Add-ons are attached with an exact count rather than via
    // withItems()' random upper bound, which can legitimately roll zero —
    // a cascade test that sometimes has nothing to cascade would pass for
    // the wrong reason.
    $order = Order::factory()->forMerchant($this->merchant)->create();
    OrderItem::factory()->count(2)->for($order)->withAddOns(2)->create();

    $itemIds = OrderItem::query()->where('order_id', $order->id)->pluck('id');

    expect($itemIds)->toHaveCount(2)
        ->and(DB::table('order_item_add_ons')->whereIn('order_item_id', $itemIds)->count())
        ->toBe(4);

    DB::table('orders')->where('id', $order->id)->delete();

    expect(DB::table('order_items')->whereIn('id', $itemIds)->count())->toBe(0)
        ->and(DB::table('order_item_add_ons')->whereIn('order_item_id', $itemIds)->count())->toBe(0);
});

test('the unique index is on (merchant_id, order_number) together', function () {
    $row = rawOrderRow($this->merchant->id, $this->user->id, ['order_number' => 'ORD-000042']);

    DB::table('orders')->insert($row);

    expect(fn () => DB::table('orders')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a misspelled attribute throws instead of being silently dropped', function () {
    // Model::preventSilentlyDiscardingAttributes() is on globally. The
    // audit found two bugs of exactly this shape: a key that didn't match
    // a fillable column vanished, and the write "succeeded" having lost
    // it. Asserted on the model rather than through a factory, because
    // Laravel builds factory models unguarded by design.
    expect(fn () => Order::query()->create(['total_cent' => 500]))
        ->toThrow(MassAssignmentException::class);

    $order = Order::factory()->forMerchant($this->merchant)->create();

    expect(fn () => $order->update(['complete_at' => now()]))
        ->toThrow(MassAssignmentException::class);
});

test('the lifecycle columns cannot be mass-assigned at all', function () {
    $order = Order::factory()->forMerchant($this->merchant)->pending()->create();

    // status / completed_at / voided_at / voided_by_user_id are not
    // fillable on purpose: the only route to them is the transition
    // Actions. Without that, any future endpoint doing $order->update(
    // $request->validated()) could close an order, or forge who voided
    // it, without ever consulting the transition map.
    expect(fn () => $order->update(['status' => 'completed']))
        ->toThrow(MassAssignmentException::class)
        ->and(fn () => $order->update(['voided_by_user_id' => $this->user->id]))
        ->toThrow(MassAssignmentException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
