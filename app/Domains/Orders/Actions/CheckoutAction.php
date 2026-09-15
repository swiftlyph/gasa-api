<?php

namespace App\Domains\Orders\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Exceptions\NoRegisterConfigured;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\CashSessions\Support\DefaultRegister;
use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Enums\BeneficiaryType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Exceptions\DiscountExceedsSubtotal;
use App\Domains\Orders\Exceptions\ProductUnavailable;
use App\Domains\Orders\Exceptions\SplitMismatch;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderBeneficiary;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\StatutoryTax;
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
 * 3. THE TAX DECOMPOSITION IS COMPUTED HERE AND SNAPSHOTTED (P10). The
 *    merchant's VAT registration and the national rate are frozen onto
 *    the order, every line records its own net/discount/payable, and the
 *    four sales buckets are rolled up from the lines — so a later change
 *    to either the merchant's toggle or the config rate can never
 *    retroactively re-tax a sale that already happened. The formulas
 *    themselves live in App\Domains\Orders\Support\StatutoryTax, not
 *    here: there is exactly one place in the codebase that divides by a
 *    VAT rate. No literal 1.12 or 0.20 appears in this class.
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
     *     register_id?: int|null,
     *     beneficiaries?: list<array{type: string, name: string, id_number: string}>,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         beneficiary?: int|null,
     *         add_ons?: list<array{name: string, price_cents: int}>
     *     }>
     * }  $payload  Already shape-validated by CheckoutRequest, whose `list`
     *              rules are what make these list<…> rather than
     *              array<array-key, …>: a JSON object would otherwise pass
     *              `array` validation and arrive with string keys.
     *              `register_id` (P4) is optional and defaults to the
     *              merchant's default register (see DefaultRegister) —
     *              it only decides WHICH register's open session, if any,
     *              the sale is attributed to; it never affects pricing or
     *              whether the checkout succeeds.
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
            $cashSessionId = $this->resolveOpenCashSessionId($payload, $merchant->getKey());

            $products = $this->resolveSellableProducts($payload['items']);

            // The tax treatment is decided ONCE, here, from the merchant
            // as it is at this moment, and then frozen onto the order.
            // Everything below reads these two locals, never the live
            // merchant or the live config again.
            $vatRegistered = (bool) $merchant->vat_registered;
            $vatRateBps = StatutoryTax::vatRateBps();

            [$lines, $subtotalCents] = $this->buildLines(
                $payload['items'],
                $products,
                $vatRegistered,
                $vatRateBps,
            );

            // Statutory first, promo second — the order the law requires,
            // and the reason the promo cap below is checked against the
            // POST-statutory subtotal rather than the gross one.
            $payableCents = array_sum(array_column($lines, 'payable_cents'));

            // THE ORDER'S statutory discount is the TOTAL RELIEF given —
            // every peso between what a beneficiary's lines would have
            // cost at the shelf price and what they actually pay. On a
            // VAT-registered order that is the 20% AND the VAT the
            // beneficiary is relieved of, because an exempt line's payable
            // is computed from the VAT-exclusive net while subtotal_cents
            // is VAT-INCLUSIVE.
            //
            // That is not a bookkeeping choice, it is the only way both
            // invariants this phase promises can hold at once:
            //
            //     total = subtotal − discount        (unchanged since P2)
            //     total = Σ payable(lines) − promo   (P10)
            //
            // Using only the 20% here would leave the VAT relieved on
            // exempt lines unaccounted for, and the two would disagree by
            // exactly that amount — which is how this was caught.
            //
            // The 20%-only figure is still recorded where it belongs: on
            // each LINE (order_items.discount_cents) and on each
            // BENEFICIARY (the amount their ID actually saved them, which
            // is what prints on the slip). See README § Tax & statutory
            // discounts for the worked example.
            $statutoryDiscountCents = 0;

            foreach ($lines as $line) {
                if ($line['beneficiary'] !== null) {
                    $statutoryDiscountCents += $line['line_total_cents'] - $line['payable_cents'];
                }
            }

            // `discount_cents` in the payload is the PROMO discount (the
            // field keeps its old name so existing POS clients keep
            // working — see CheckoutRequest).
            $promoDiscountCents = $payload['discount_cents'] ?? 0;

            if ($promoDiscountCents > $payableCents) {
                // Deliberately the EXISTING code and exception: from a
                // client's point of view this is the same condition it
                // always was — "your discount is bigger than what's left
                // to pay" — and the only thing P10 changed is that "what's
                // left" is now net of the statutory discount.
                throw new DiscountExceedsSubtotal($payableCents, $promoDiscountCents);
            }

            $discountCents = $statutoryDiscountCents + $promoDiscountCents;
            $totalCents = $subtotalCents - $discountCents;

            $buckets = $this->salesBuckets($lines, $vatRegistered);

            $method = PaymentMethod::from($payload['payment_method']);
            [$cashCents, $gcashCents] = $this->resolvePaymentSplit($method, $payload, $totalCents);

            // Only now, with everything proven consistent, is a number
            // drawn. The lock it takes is released when this transaction
            // commits — with the order row inside it.
            $order = new Order;

            $order->fill([
                'order_number' => $this->orderNumbers->execute($merchant->getKey()),
                'subtotal_cents' => $subtotalCents,

                // KEEPS its pre-P10 meaning: every peso off this order.
                // A CHECK constraint enforces that it equals the two
                // columns below summed, because every existing report
                // reads it as "total discounts" and must keep doing so.
                'discount_cents' => $discountCents,
                'statutory_discount_cents' => $statutoryDiscountCents,
                'promo_discount_cents' => $promoDiscountCents,

                'total_cents' => $totalCents,

                // Frozen at sale time, never re-read from the merchant or
                // the config afterwards — a shop that registers for VAT
                // in March must not retroactively re-tax January.
                'vat_registered_snapshot' => $vatRegistered,
                'vat_rate_bps_snapshot' => $vatRegistered ? $vatRateBps : 0,

                'vatable_sales_cents' => $buckets['vatable_sales_cents'],
                'vat_cents' => $buckets['vat_cents'],
                'vat_exempt_sales_cents' => $buckets['vat_exempt_sales_cents'],
                'nonvat_sales_cents' => $buckets['nonvat_sales_cents'],
                'currency' => $this->currencyFor($products),
                'payment_method' => $method,
                'cash_cents' => $cashCents,
                'gcash_cents' => $gcashCents,
                'created_by_user_id' => $cashier->getKey(),

                // Null when no session is open on the register in context
                // — a shop that forgot to open the till must not be
                // blocked from selling (see resolveOpenCashSessionId()).
                'cash_session_id' => $cashSessionId,

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

            // Beneficiaries first: the lines carry a FK to them, so the
            // rows have to exist before persistLines() can point at them.
            // Both happen inside this same transaction, so a failure in
            // either takes the whole sale with it.
            $beneficiaryIds = $this->persistBeneficiaries($order, $payload['beneficiaries'] ?? [], $lines);

            $this->persistLines($order, $lines, $beneficiaryIds);

            return $order;
        });
    }

    /**
     * The open session (if any) on the register in context, so the sale
     * can be attributed to it. Register selection defaults to the
     * merchant's default register (see DefaultRegister) when the payload
     * doesn't name one.
     *
     * Returns null, and NEVER throws, when there is no register to
     * attribute to or no session open on it — a shop that forgot to open
     * the till, or has no register configured at all (every merchant
     * fixture created before P4, in particular), must not be blocked from
     * selling. This is the one place P4 touches checkout, and it is
     * deliberately just an attribution stamp: it has no opinion on
     * pricing, discounts, or whether the checkout itself succeeds.
     *
     * @param  array{register_id?: int|null, ...}  $payload
     */
    private function resolveOpenCashSessionId(array $payload, int $merchantId): ?int
    {
        try {
            $register = isset($payload['register_id'])
                ? Register::query()->find($payload['register_id'])
                : DefaultRegister::for($merchantId);
        } catch (NoRegisterConfigured) {
            // Deliberately caught rather than propagated: checkout is not
            // where "this merchant has no register" should ever surface —
            // see the class docblock above.
            return null;
        }

        if ($register === null) {
            // A register_id that doesn't resolve (foreign, or simply
            // wrong) is treated exactly like "no register named" would be
            // treated if this merchant had none at all: no session to
            // attribute to, sale proceeds regardless. Checkout is not the
            // place to validate a register id — that would make an
            // unrelated typo block a sale.
            return null;
        }

        return CashSession::query()
            ->where('register_id', $register->getKey())
            ->where('status', 'open')
            ->value('id');
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
     * Prices every line from the resolved products, decomposes each for
     * tax, and returns the lines plus the order subtotal.
     *
     * Add-ons are priced PER UNIT: two lattes each with an extra shot is
     * two extra shots. The formula is
     * (unit_price + add_ons_per_unit) * quantity, which is the spec's
     * "unit_price * quantity + add_on_price * quantity" factored.
     *
     * `line_total_cents` is UNCHANGED from before P10 — the pre-discount,
     * VAT-inclusive amount charged for the line. The three new figures
     * decompose it, per the rules in StatutoryTax:
     *
     *   VAT merchant, beneficiary line:  net = line_total / 1.12
     *                                    discount = 20% of net
     *                                    payable  = net - discount
     *   VAT merchant, ordinary line:     net = line_total / 1.12
     *                                    payable = line_total (VAT stays in)
     *   non-VAT, beneficiary line:       discount = 20% of line_total
     *                                    payable  = line_total - discount
     *   non-VAT, ordinary line:          payable  = line_total
     *
     * Rounding is half-up at each step, and happens ONLY inside
     * StatutoryTax — there is no rate literal anywhere in this method.
     *
     * @param  list<array{product_id: int, quantity: int, beneficiary?: int|null, add_ons?: list<array{name: string, price_cents: int}>}>  $items
     * @param  Collection<int, Product>  $products
     * @return array{0: list<array{
     *     product_id: int, product_name: string, unit_price_cents: int,
     *     quantity: int, line_total_cents: int, beneficiary: int|null,
     *     net_of_vat_cents: int, discount_cents: int, payable_cents: int,
     *     add_ons: list<array{name: string, price_cents: int}>
     * }>, 1: int}
     */
    private function buildLines(array $items, Collection $products, bool $vatRegistered, int $vatRateBps): array
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

            $beneficiaryIndex = isset($item['beneficiary']) ? (int) $item['beneficiary'] : null;

            [$netOfVatCents, $discountCents, $payableCents] = $this->decomposeLine(
                $lineTotalCents,
                isBeneficiary: $beneficiaryIndex !== null,
                vatRegistered: $vatRegistered,
                vatRateBps: $vatRateBps,
            );

            $lines[] = [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'unit_price_cents' => $unitPriceCents,
                'quantity' => $quantity,
                'line_total_cents' => $lineTotalCents,
                'beneficiary' => $beneficiaryIndex,
                'net_of_vat_cents' => $netOfVatCents,
                'discount_cents' => $discountCents,
                'payable_cents' => $payableCents,
                'add_ons' => $addOns,
            ];

            $subtotalCents += $lineTotalCents;
        }

        return [$lines, $subtotalCents];
    }

    /**
     * One line's tax decomposition: [net_of_vat, statutory_discount,
     * payable]. The four cases of the rule, in one place, each expressed
     * through StatutoryTax rather than arithmetic of its own.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function decomposeLine(
        int $lineTotalCents,
        bool $isBeneficiary,
        bool $vatRegistered,
        int $vatRateBps,
    ): array {
        if (! $vatRegistered) {
            // No VAT was ever in the price, so the line's "net of VAT" is
            // its full amount — NOT zero, which would read as "this line
            // was worth nothing" (see the order_items migration).
            $discountCents = $isBeneficiary
                ? StatutoryTax::percentageOf($lineTotalCents, StatutoryTax::statutoryDiscountBps())
                : 0;

            return [$lineTotalCents, $discountCents, $lineTotalCents - $discountCents];
        }

        $netOfVatCents = StatutoryTax::netOfVat($lineTotalCents, $vatRateBps);

        if (! $isBeneficiary) {
            // The VAT stays in what this customer pays; the split into
            // vatable_sales + vat is a reporting decomposition of the same
            // money, not a reduction of it.
            return [$netOfVatCents, 0, $lineTotalCents];
        }

        // A beneficiary is relieved of BOTH the VAT and a further 20% —
        // which is why the discount is taken off the NET, never off the
        // shelf price. The net itself becomes VAT-exempt sales.
        $discountCents = StatutoryTax::percentageOf($netOfVatCents, StatutoryTax::statutoryDiscountBps());

        return [$netOfVatCents, $discountCents, $netOfVatCents - $discountCents];
    }

    /**
     * Rolls the per-line figures up into the order's four sales buckets.
     *
     * A VAT-registered order populates the first three and leaves
     * nonvat_sales at zero; a non-VAT order does the exact reverse. They
     * are kept apart rather than merged because "sales we owe VAT on" and
     * "sales we don't" are the two numbers a BIR filing asks for.
     *
     * @param  list<array{line_total_cents: int, beneficiary: int|null, net_of_vat_cents: int, discount_cents: int, payable_cents: int}>  $lines
     * @return array{vatable_sales_cents: int, vat_cents: int, vat_exempt_sales_cents: int, nonvat_sales_cents: int}
     */
    private function salesBuckets(array $lines, bool $vatRegistered): array
    {
        $buckets = [
            'vatable_sales_cents' => 0,
            'vat_cents' => 0,
            'vat_exempt_sales_cents' => 0,
            'nonvat_sales_cents' => 0,
        ];

        foreach ($lines as $line) {
            if (! $vatRegistered) {
                // Every line, beneficiary or not, is non-VAT sales. Booked
                // at the PRE-discount amount, exactly as vatable_sales and
                // vat_exempt_sales are for a VAT merchant: these buckets
                // partition the order's SUBTOTAL by tax treatment, and the
                // discounts are reported separately.
                $buckets['nonvat_sales_cents'] += $line['line_total_cents'];

                continue;
            }

            if ($line['beneficiary'] !== null) {
                // No VAT is due on a beneficiary's line at all — the net
                // is the exempt sale, and the VAT that would have been on
                // it is simply never collected.
                $buckets['vat_exempt_sales_cents'] += $line['net_of_vat_cents'];

                continue;
            }

            $buckets['vatable_sales_cents'] += $line['net_of_vat_cents'];

            // Derived by subtraction, per line, so the buckets always add
            // back up to the line totals they came from — see
            // StatutoryTax::vatOn().
            $buckets['vat_cents'] += $line['line_total_cents'] - $line['net_of_vat_cents'];
        }

        return $buckets;
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
     * Writes one row per declared beneficiary, with THAT beneficiary's own
     * totals rolled up from the lines assigned to them, and returns the
     * payload-index => database-id map the lines need.
     *
     * The totals are stored rather than derived on read for the same
     * reason line_total_cents is: the figure printed on the slip must
     * still be the figure years later. Because they are summed here from
     * the very lines that are about to be written, in the same
     * transaction, they can never disagree with those lines.
     *
     * Every declared beneficiary is guaranteed to own at least one line —
     * CheckoutRequest rejects the alternative with `beneficiary_unused`
     * before this runs — so no row written here is ever a zero-discount
     * orphan.
     *
     * @param  list<array{type: string, name: string, id_number: string}>  $beneficiaries
     * @param  list<array{beneficiary: int|null, net_of_vat_cents: int, discount_cents: int}>  $lines
     * @return array<int, int> payload index => order_beneficiaries.id
     */
    private function persistBeneficiaries(Order $order, array $beneficiaries, array $lines): array
    {
        $ids = [];

        foreach ($beneficiaries as $index => $beneficiary) {
            $discountCents = 0;
            $vatExemptSalesCents = 0;

            foreach ($lines as $line) {
                if ($line['beneficiary'] !== (int) $index) {
                    continue;
                }

                $discountCents += $line['discount_cents'];

                // Only meaningful on a VAT-registered order; on a non-VAT
                // one every line's statutory discount is computed off the
                // gross and there is no exempt sale to record, so this
                // stays 0 — which is why it reads the order's snapshot
                // rather than assuming.
                if ($order->vat_registered_snapshot) {
                    $vatExemptSalesCents += $line['net_of_vat_cents'];
                }
            }

            /** @var OrderBeneficiary $record */
            $record = $order->beneficiaries()->create([
                'type' => BeneficiaryType::from($beneficiary['type']),
                'name' => $beneficiary['name'],
                'id_number' => $beneficiary['id_number'],
                'discount_cents' => $discountCents,
                'vat_exempt_sales_cents' => $vatExemptSalesCents,
            ]);

            $ids[(int) $index] = (int) $record->getKey();
        }

        return $ids;
    }

    /**
     * @param  list<array{
     *     product_id: int, product_name: string, unit_price_cents: int,
     *     quantity: int, line_total_cents: int, beneficiary: int|null,
     *     net_of_vat_cents: int, discount_cents: int, payable_cents: int,
     *     add_ons: list<array{name: string, price_cents: int}>
     * }>  $lines
     * @param  array<int, int>  $beneficiaryIds  payload index => database id
     */
    private function persistLines(Order $order, array $lines, array $beneficiaryIds = []): void
    {
        foreach ($lines as $line) {
            /** @var OrderItem $item */
            $item = $order->items()->create([
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'unit_price_cents' => $line['unit_price_cents'],
                'quantity' => $line['quantity'],
                'line_total_cents' => $line['line_total_cents'],

                'beneficiary_id' => $line['beneficiary'] === null
                    ? null
                    : ($beneficiaryIds[$line['beneficiary']] ?? null),

                'net_of_vat_cents' => $line['net_of_vat_cents'],
                'discount_cents' => $line['discount_cents'],
                'payable_cents' => $line['payable_cents'],
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
