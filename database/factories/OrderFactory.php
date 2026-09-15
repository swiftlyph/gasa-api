<?php

namespace Database\Factories;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Actions\GenerateOrderNumberAction;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orders for tests and the dev seeder. There is no order-creation
 * endpoint yet (P2's checkout), so this factory is currently the only way
 * an order comes into existence.
 *
 * It issues numbers through the real GenerateOrderNumberAction rather
 * than making up a string. That matters: a factory that numbered orders
 * its own way would let the numbering tests pass against a mechanism
 * production never uses, which is the exact bug class those tests exist
 * to catch. The side effect is that ->make() (build without saving) still
 * consumes a counter value — harmless, and worth it.
 *
 * Every money field it produces is internally consistent: total equals
 * subtotal minus discount, a split's cash and gcash sum to the total, and
 * withItems() recomputes all of them from the lines it created. P10 adds
 * the tax columns to that guarantee — discount_cents always equals
 * statutory + promo (a CHECK constraint, not a convention), and a
 * default fixture is a non-VAT sale whose nonvat_sales_cents tracks its
 * subtotal.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<Order>
     */
    protected $model = Order::class;

    /**
     * Key order is load-bearing. Laravel resolves relation values first,
     * then evaluates closures top-to-bottom writing each result back, so
     * `order_number` can read the resolved `merchant_id`, and the split
     * amounts below can read the computed `total_cents`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),

            'order_number' => fn (array $attributes) => $this->nextOrderNumber((int) $attributes['merchant_id']),

            'status' => OrderStatus::Pending,

            'subtotal_cents' => fake()->numberBetween(85, 1500) * 100,
            'discount_cents' => 0,

            // P10: `discount_cents` is constrained to equal statutory +
            // promo (orders_discount_split_check), so a factory that set
            // only the former would write a row the database rejects.
            // Any discount a test asks for with ['discount_cents' => n]
            // is a MANUAL one — that is what the field meant before P10
            // and what every existing caller means by it — so it mirrors
            // into promo, exactly as the migration backfills pre-P10
            // rows. A test that wants a statutory discount says so
            // explicitly (or, better, goes through the real checkout).
            'promo_discount_cents' => fn (array $attributes) => $attributes['discount_cents'],
            'statutory_discount_cents' => 0,

            // Non-VAT by default, matching merchants.vat_registered's own
            // default: the whole subtotal is non-VAT sales, no VAT was
            // ever extracted. Mirrors what CheckoutAction snapshots for a
            // non-VAT merchant, so factory-built and checkout-built
            // orders agree.
            'vat_registered_snapshot' => false,
            'vat_rate_bps_snapshot' => 0,
            'vatable_sales_cents' => 0,
            'vat_cents' => 0,
            'vat_exempt_sales_cents' => 0,
            'nonvat_sales_cents' => fn (array $attributes) => $attributes['subtotal_cents'],

            'total_cents' => fn (array $attributes) => $attributes['subtotal_cents'] - $attributes['discount_cents'],
            'currency' => 'PHP',

            'payment_method' => PaymentMethod::Cash,
            'cash_cents' => null,
            'gcash_cents' => null,

            'created_by_user_id' => User::factory(),

            'completed_at' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
        ];
    }

    /**
     * Pins the order to an existing merchant, and to one of that
     * merchant's own users as the cashier — a cashier from a different
     * merchant would be a nonsensical fixture that quietly passes tests
     * about who rang an order up.
     */
    public function forMerchant(Merchant $merchant, ?User $cashier = null): static
    {
        return $this->state(fn () => [
            'merchant_id' => $merchant->getKey(),
            'created_by_user_id' => $cashier?->getKey()
                ?? $merchant->users()->value('users.id')
                ?? User::factory(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Pending,
            'completed_at' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
        ]);
    }

    /**
     * A completed order carries its completed_at, and nothing else — the
     * void columns stay null. States that leave both timestamps set would
     * describe an order that can't exist.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
            'voided_at' => null,
            'voided_by_user_id' => null,
        ]);
    }

    public function voided(?User $voidedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Voided,
            'completed_at' => null,
            'voided_at' => now(),

            // Falls back to the cashier voiding their own order, which is
            // the common real case, rather than inventing a second user.
            'voided_by_user_id' => $voidedBy?->getKey() ?? $attributes['created_by_user_id'],
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethod::Cash,
            'cash_cents' => null,
            'gcash_cents' => null,
        ]);
    }

    public function gcash(): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethod::Gcash,
            'cash_cents' => null,
            'gcash_cents' => null,
        ]);
    }

    /**
     * Splits the total down the middle, remainder to gcash, so
     * cash_cents + gcash_cents === total_cents exactly — the invariant
     * P2's checkout will validate. Integer division only; a split that
     * used floats could land a centavo off and make the fixture a lie.
     */
    public function split(): static
    {
        return $this->state(fn () => [
            'payment_method' => PaymentMethod::Split,
            'cash_cents' => fn (array $attributes) => intdiv((int) $attributes['total_cents'], 2),
            'gcash_cents' => fn (array $attributes) => (int) $attributes['total_cents']
                - intdiv((int) $attributes['total_cents'], 2),
        ]);
    }

    /**
     * Attaches lines and then RECOMPUTES the order's money from them, so
     * the header and the lines always agree. An order fixture whose total
     * doesn't equal its items is worse than no fixture: every test that
     * reads it is testing a state production can't produce.
     *
     * @param  Collection<int, Product>|null  $products  snapshot sources; raw
     *                                                   values are used when omitted
     * @param  int  $maxAddOns  UPPER BOUND, not a count — each line gets a
     *                          random 0..$maxAddOns add-ons, which is what
     *                          makes seeded data look real. A test that
     *                          needs add-ons to definitely exist should
     *                          attach them with OrderItemFactory::
     *                          withAddOns($exactCount) instead.
     */
    public function withItems(int $count = 2, ?Collection $products = null, int $maxAddOns = 0): static
    {
        return $this->afterCreating(function (Order $order) use ($count, $products, $maxAddOns): void {
            $subtotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $factory = OrderItem::factory()->for($order);

                if ($products !== null && $products->isNotEmpty()) {
                    $factory = $factory->forProduct($products->random());
                }

                if ($maxAddOns > 0) {
                    $factory = $factory->withAddOns(fake()->numberBetween(0, $maxAddOns));
                }

                $subtotal += $factory->create()->line_total_cents;
            }

            $order->subtotal_cents = $subtotal;
            $order->total_cents = $subtotal - $order->discount_cents;

            // Kept in step with the recomputed subtotal for a non-VAT
            // fixture, where the two are the same figure by definition. A
            // VAT-registered fixture is not built this way (its buckets
            // depend on which lines belong to a beneficiary), so this
            // deliberately only touches the non-VAT case.
            if (! $order->vat_registered_snapshot) {
                $order->nonvat_sales_cents = $subtotal;
            }

            if ($order->payment_method->isSplit()) {
                $order->cash_cents = intdiv($order->total_cents, 2);
                $order->gcash_cents = $order->total_cents - $order->cash_cents;
            }

            $order->save();
        });
    }

    /**
     * The counter lock has to be held until the numbered row commits, so
     * the action refuses to run outside a transaction. Inside the test
     * suite one is always already open (RefreshDatabase), and this nests
     * as a savepoint; in the seeder it opens a real one per order.
     */
    private function nextOrderNumber(int $merchantId): string
    {
        return DB::transaction(
            fn (): string => app(GenerateOrderNumberAction::class)->execute($merchantId),
        );
    }
}
