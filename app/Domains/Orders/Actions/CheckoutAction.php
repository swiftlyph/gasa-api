<?php

namespace App\Domains\Orders\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Exceptions\DiscountExceedsSubtotal;
use App\Domains\Orders\Exceptions\ProductUnavailable;
use App\Domains\Orders\Exceptions\SplitMismatch;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * POS checkout: turns a basket into a paid order, in ONE transaction.
 *
 * The two rules this class exists to enforce:
 *
 * 1. PRICES COME FROM THE DATABASE, NEVER THE PAYLOAD. The client sends
 *    product ids and quantities; every unit price is read from `products`
 *    here and snapshotted onto the line. The audited system trusted
 *    client-submitted prices, which meant anyone who could post a request
 *    could set their own. Nothing in this method reads a price out of
 *    $payload — that is the fix, and CheckoutRequest not defining a price
 *    rule is the second, independent layer of it.
 *
 * 2. NOTHING IS WRITTEN UNTIL EVERYTHING IS VALID. Products, discount and
 *    split are all checked before the order number is drawn, so a failed
 *    checkout does not burn a sequence number. Anything that still fails
 *    afterwards rolls back inside the transaction, counter increment
 *    included — the counter row lock is deliberately held by this same
 *    transaction (see GenerateOrderNumberAction).
 *
 * Add-ons are the one thing that IS client-supplied, because no add-on
 * catalog exists to look them up in yet. CheckoutRequest bounds them
 * (count per line, non-empty name, price range); see the README's add-on
 * TODO. When the catalog module adds a real add-on table, they should be
 * resolved here exactly like products are.
 *
 * Must be called with $cashier as the AUTHENTICATED user: BelongsToMerchant
 * stamps merchant_id from whoever is authenticated, so passing someone else
 * would file the order under one merchant while pricing it from another's
 * catalog. The merchant is read back off $cashier for the same reason —
 * one source of truth for whose order this is.
 */
class CheckoutAction
{
    public function __construct(
        private readonly GenerateOrderNumberAction $orderNumbers,
    ) {}

    /**
     * @param  array{
     *     payment_method: string,
     *     cash_cents?: int|null,
     *     gcash_cents?: int|null,
     *     discount_cents?: int|null,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         add_ons?: list<array{name: string, price_cents: int}>
     *     }>
     * }  $payload  Already shape-validated by CheckoutRequest, whose `list`
     *              rules are what make these list<…> rather than
     *              array<array-key, …>: a JSON object would otherwise pass
     *              `array` validation and arrive with string keys.
     */
    public function execute(array $payload, User $cashier): Order
    {
        $merchant = $cashier->merchant();

        if ($merchant === null) {
            // Unreachable through merchant.api — EnsureMerchantActive has
            // already returned 403 — but this Action must not be able to
            // create an untenanted order if it is ever called from a
            // command or a job.
            throw new ApiException('Your merchant account is not active.', 'merchant_inactive', 403);
        }

        return DB::transaction(function () use ($payload, $cashier, $merchant): Order {
            $products = $this->resolveSellableProducts($payload['items']);

            [$lines, $subtotalCents] = $this->buildLines($payload['items'], $products);

            $discountCents = $payload['discount_cents'] ?? 0;

            if ($discountCents > $subtotalCents) {
                throw new DiscountExceedsSubtotal($subtotalCents, $discountCents);
            }

            $totalCents = $subtotalCents - $discountCents;

            $method = PaymentMethod::from($payload['payment_method']);
            [$cashCents, $gcashCents] = $this->resolvePaymentSplit($method, $payload, $totalCents);

            // Only now, with everything proven consistent, is a number
            // drawn. The lock it takes is released when this transaction
            // commits — with the order row inside it.
            $order = new Order;

            $order->fill([
                'order_number' => $this->orderNumbers->execute($merchant->getKey()),
                'subtotal_cents' => $subtotalCents,
                'discount_cents' => $discountCents,
                'total_cents' => $totalCents,
                'currency' => $this->currencyFor($products),
                'payment_method' => $method,
                'cash_cents' => $cashCents,
                'gcash_cents' => $gcashCents,
                'created_by_user_id' => $cashier->getKey(),

                // merchant_id is intentionally absent: BelongsToMerchant
                // stamps it from the authenticated user and overwrites
                // anything set here, so passing one would only be
                // misleading.
            ]);

            // Assigned rather than filled, because `status` is not
            // fillable — the same reason the transition Actions assign it
            // directly. Relying on the column's `pending` default instead
            // would leave the attribute unset on the model we return, so
            // the 201 response would carry a null status even though the
            // stored row was correct.
            $order->status = OrderStatus::Pending;

            $order->save();

            $this->persistLines($order, $lines);

            return $order;
        });
    }

    /**
     * Loads every referenced product, tenant-scoped, and rejects the whole
     * basket if any of them cannot be sold.
     *
     * All-or-nothing on purpose: a POS that silently dropped the
     * unavailable line would hand the customer a receipt missing the drink
     * they just paid for. The cashier gets told which tiles failed and
     * re-rings the order.
     *
     * @param  list<array{product_id: int, quantity: int, add_ons?: list<array{name: string, price_cents: int}>}>  $items
     * @return Collection<int, Product> keyed by product id
     */
    private function resolveSellableProducts(array $items): Collection
    {
        /** @var Collection<int, int> $requestedIds */
        $requestedIds = (new Collection($items))
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        // The global scope does the tenancy work: another merchant's
        // product id is not "forbidden", it simply does not exist as far
        // as this query is concerned, so it lands in $unsellable below
        // alongside genuinely missing and unavailable ids.
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        $unsellable = $requestedIds
            ->reject(fn (int $id): bool => $products->get($id)?->is_available === true)
            ->values();

        if ($unsellable->isNotEmpty()) {
            throw new ProductUnavailable($unsellable->all());
        }

        return $products;
    }

    /**
     * Prices every line from the resolved products and returns the lines
     * plus the order subtotal.
     *
     * Add-ons are priced PER UNIT: two lattes each with an extra shot is
     * two extra shots. The formula is
     * (unit_price + add_ons_per_unit) * quantity, which is the spec's
     * "unit_price * quantity + add_on_price * quantity" factored.
     *
     * @param  list<array{product_id: int, quantity: int, add_ons?: list<array{name: string, price_cents: int}>}>  $items
     * @param  Collection<int, Product>  $products
     * @return array{0: list<array{
     *     product_id: int, product_name: string, unit_price_cents: int,
     *     quantity: int, line_total_cents: int,
     *     add_ons: list<array{name: string, price_cents: int}>
     * }>, 1: int}
     */
    private function buildLines(array $items, Collection $products): array
    {
        $lines = [];
        $subtotalCents = 0;

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $products->get((int) $item['product_id']);

            $addOns = $item['add_ons'] ?? [];
            $addOnCentsPerUnit = array_sum(array_column($addOns, 'price_cents'));

            $quantity = (int) $item['quantity'];

            // Snapshot, taken once, here. From this point the line owes
            // nothing to the catalog row it came from.
            $unitPriceCents = $product->price_cents;
            $lineTotalCents = ($unitPriceCents + $addOnCentsPerUnit) * $quantity;

            $lines[] = [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'unit_price_cents' => $unitPriceCents,
                'quantity' => $quantity,
                'line_total_cents' => $lineTotalCents,
                'add_ons' => $addOns,
            ];

            $subtotalCents += $lineTotalCents;
        }

        return [$lines, $subtotalCents];
    }

    /**
     * @param  array{payment_method: string, cash_cents?: int|null, gcash_cents?: int|null, discount_cents?: int|null, items: mixed}  $payload
     * @return array{0: int|null, 1: int|null}
     */
    private function resolvePaymentSplit(PaymentMethod $method, array $payload, int $totalCents): array
    {
        if (! $method->isSplit()) {
            // Both null, never zero — see the orders migration.
            return [null, null];
        }

        // Present and positive by CheckoutRequest; the sum is checked here
        // because only the server knows the total.
        $cashCents = (int) $payload['cash_cents'];
        $gcashCents = (int) $payload['gcash_cents'];

        if ($cashCents + $gcashCents !== $totalCents) {
            throw new SplitMismatch($cashCents, $gcashCents, $totalCents);
        }

        return [$cashCents, $gcashCents];
    }

    /**
     * The order's currency comes from the catalog it was priced against,
     * not from a default, so an order always states the currency its
     * amounts are actually in.
     *
     * Assumes a merchant's catalog is single-currency, which the products
     * table makes true today (one `currency` column, defaulted to PHP, and
     * no endpoint that changes it). If the catalog module ever allows
     * mixed currencies per merchant, this needs a real decision — mixing
     * them in one order's integer totals would be silently wrong.
     *
     * @param  Collection<int, Product>  $products
     */
    private function currencyFor(Collection $products): string
    {
        /** @var Product $first */
        $first = $products->first();

        return $first->currency;
    }

    /**
     * @param  list<array{
     *     product_id: int, product_name: string, unit_price_cents: int,
     *     quantity: int, line_total_cents: int,
     *     add_ons: list<array{name: string, price_cents: int}>
     * }>  $lines
     */
    private function persistLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            /** @var OrderItem $item */
            $item = $order->items()->create([
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'unit_price_cents' => $line['unit_price_cents'],
                'quantity' => $line['quantity'],
                'line_total_cents' => $line['line_total_cents'],
            ]);

            foreach ($line['add_ons'] as $addOn) {
                $item->addOns()->create([
                    'name' => $addOn['name'],
                    'price_cents' => $addOn['price_cents'],
                ]);
            }
        }
    }
}
