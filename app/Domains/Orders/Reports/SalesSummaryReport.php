<?php

namespace App\Domains\Orders\Reports;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * GET /merchant/reports/sales-summary — one row of aggregate figures for a
 * date range. See README § Reporting for the accounting rules this class
 * exists to enforce; restated briefly here because they drive every query
 * below:
 *
 *  - VOIDED orders never contribute to revenue — gross, discount, net, and
 *    every payment-method bucket all exclude them. Their count is reported
 *    separately (`voided_count`) so "how many sales" and "how many voids"
 *    stay two different numbers.
 *  - PENDING orders DO count as revenue: this is a counter-service shop,
 *    the customer paid at creation, and "not yet completed" describes the
 *    drink, not the sale.
 *  - A split order's `cash_cents`/`gcash_cents` land in their own buckets,
 *    summing to the order's total exactly once — never the order's full
 *    total double-counted into both.
 *
 * `by_payment_method.{cash,gcash}.count` is how many orders were paid
 * PURELY that way — a split order counts once, under `split`, not under
 * both. Its money still lands in all three `amount_cents` figures, since
 * the drawer and the gcash settlement both genuinely received their share
 * (this is the same accounting `ReconcileCashSessionAction` uses).
 *
 * Every figure is ONE query: a single row of conditional aggregates
 * (FILTER (WHERE ...), native to Postgres 16) rather than one query per
 * figure or — worse — loading orders into PHP to sum them. See
 * tests/Feature/Reports/ReportingTest.php for the query-count assertion
 * this earns.
 */
class SalesSummaryReport
{
    /**
     * @return array{
     *     orders_count: int,
     *     completed_count: int,
     *     voided_count: int,
     *     gross_cents: int,
     *     discount_cents: int,
     *     net_cents: int,
     *     statutory_discount_cents: int,
     *     promo_discount_cents: int,
     *     vatable_sales_cents: int,
     *     vat_cents: int,
     *     vat_exempt_sales_cents: int,
     *     nonvat_sales_cents: int,
     *     by_payment_method: array<string, array{count: int, amount_cents: int}>,
     *     average_order_cents: int,
     * }
     */
    public function generate(CarbonImmutable $fromUtc, CarbonImmutable $toUtcExclusive): array
    {
        $row = Order::query()
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtcExclusive)
            ->toBase()
            ->selectRaw(
                <<<'SQL'
                    count(*) as orders_count,
                    count(*) filter (where status = ?) as completed_count,
                    count(*) filter (where status = ?) as voided_count,

                    -- Revenue figures: every non-voided order (pending +
                    -- completed), never voided.
                    coalesce(sum(subtotal_cents) filter (where status != ?), 0) as gross_cents,
                    coalesce(sum(discount_cents) filter (where status != ?), 0) as discount_cents,
                    coalesce(sum(total_cents) filter (where status != ?), 0) as net_cents,

                    -- P10. discount_cents above stays "every peso off";
                    -- these two say why, and always sum back to it. The
                    -- four buckets partition the subtotal by tax
                    -- treatment. Same voided-exclusion rule as every
                    -- figure above — a voided discounted order must not
                    -- contribute its discount either.
                    coalesce(sum(statutory_discount_cents) filter (where status != ?), 0) as statutory_discount_cents,
                    coalesce(sum(promo_discount_cents) filter (where status != ?), 0) as promo_discount_cents,
                    coalesce(sum(vatable_sales_cents) filter (where status != ?), 0) as vatable_sales_cents,
                    coalesce(sum(vat_cents) filter (where status != ?), 0) as vat_cents,
                    coalesce(sum(vat_exempt_sales_cents) filter (where status != ?), 0) as vat_exempt_sales_cents,
                    coalesce(sum(nonvat_sales_cents) filter (where status != ?), 0) as nonvat_sales_cents,

                    count(*) filter (where status != ? and payment_method = ?) as cash_count,
                    coalesce(sum(total_cents) filter (where status != ? and payment_method = ?), 0) as cash_amount_cents,

                    count(*) filter (where status != ? and payment_method = ?) as gcash_count,
                    coalesce(sum(total_cents) filter (where status != ? and payment_method = ?), 0) as gcash_amount_cents,

                    count(*) filter (where status != ? and payment_method = ?) as split_count,
                    coalesce(sum(cash_cents) filter (where status != ? and payment_method = ?), 0) as split_cash_amount_cents,
                    coalesce(sum(gcash_cents) filter (where status != ? and payment_method = ?), 0) as split_gcash_amount_cents
                    SQL,
                [
                    OrderStatus::Completed->value,
                    OrderStatus::Voided->value,

                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,

                    // P10's six additive figures, same voided exclusion.
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,
                    OrderStatus::Voided->value,

                    OrderStatus::Voided->value, PaymentMethod::Cash->value,
                    OrderStatus::Voided->value, PaymentMethod::Cash->value,

                    OrderStatus::Voided->value, PaymentMethod::Gcash->value,
                    OrderStatus::Voided->value, PaymentMethod::Gcash->value,

                    OrderStatus::Voided->value, PaymentMethod::Split->value,
                    OrderStatus::Voided->value, PaymentMethod::Split->value,
                    OrderStatus::Voided->value, PaymentMethod::Split->value,
                ],
            )
            ->first();

        $ordersCount = (int) $row->orders_count;
        $voidedCount = (int) $row->voided_count;
        $netCents = (int) $row->net_cents;

        // The denominator is orders that actually contributed revenue —
        // orders_count minus voided_count — not every order in the range.
        // Dividing net_cents (which already excludes voided orders) by a
        // count that includes them would understate the average with
        // every void, which is backwards: a void should not change what a
        // typical SALE looks like.
        $revenueOrdersCount = $ordersCount - $voidedCount;

        // A split order's cash and gcash portions are added into their
        // respective buckets — cash gets the split's cash_cents, gcash
        // gets its gcash_cents — never the split's total counted whole
        // into either, and never counted in both.
        $cashAmountCents = (int) $row->cash_amount_cents + (int) $row->split_cash_amount_cents;
        $gcashAmountCents = (int) $row->gcash_amount_cents + (int) $row->split_gcash_amount_cents;

        return [
            'orders_count' => $ordersCount,
            'completed_count' => (int) $row->completed_count,
            'voided_count' => (int) $row->voided_count,
            'gross_cents' => (int) $row->gross_cents,
            'discount_cents' => (int) $row->discount_cents,
            'net_cents' => $netCents,

            'statutory_discount_cents' => (int) $row->statutory_discount_cents,
            'promo_discount_cents' => (int) $row->promo_discount_cents,
            'vatable_sales_cents' => (int) $row->vatable_sales_cents,
            'vat_cents' => (int) $row->vat_cents,
            'vat_exempt_sales_cents' => (int) $row->vat_exempt_sales_cents,
            'nonvat_sales_cents' => (int) $row->nonvat_sales_cents,

            'by_payment_method' => [
                'cash' => [
                    'count' => (int) $row->cash_count,
                    'amount_cents' => $cashAmountCents,
                ],
                'gcash' => [
                    'count' => (int) $row->gcash_count,
                    'amount_cents' => $gcashAmountCents,
                ],
                'split' => [
                    'count' => (int) $row->split_count,
                    'amount_cents' => (int) $row->split_cash_amount_cents + (int) $row->split_gcash_amount_cents,
                ],
            ],
            // Integer division deliberately: cents, not fractional cents.
            'average_order_cents' => $revenueOrdersCount > 0 ? intdiv($netCents, $revenueOrdersCount) : 0,
        ];
    }
}
