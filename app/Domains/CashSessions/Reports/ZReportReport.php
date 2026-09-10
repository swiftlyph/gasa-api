<?php

namespace App\Domains\CashSessions\Reports;

use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use App\Domains\Orders\Models\Order;

/**
 * The sales half of a Z-report: every figure attributable to ONE cash
 * session, addressed by `cash_session_id` — never by a date range. This is
 * the deliberate difference from every report in
 * App\Domains\Orders\Reports: those ask "what happened in this date
 * range," this asks "what happened in this till shift," and an order
 * rung up just before midnight on an overnight shift belongs to the
 * session that was open when it was created, not to whichever calendar
 * day it lands on.
 *
 * Same accounting rules as SalesSummaryReport/TopItemsReport (see their
 * docblocks) — voided orders excluded from revenue, pending included,
 * split orders' cash/gcash portions landing in their own buckets exactly
 * once — because a Z-report and a date-range report over the SAME orders
 * must never disagree about what those orders were worth. The
 * reconciliation (expected cash) block is deliberately NOT computed here:
 * that stays ReconcileCashSessionAction's job alone (see
 * ZReportController/CashSessionController — the resource composes both).
 *
 * One query for the sales aggregate (conditional aggregates, the same
 * FILTER (WHERE ...) shape SalesSummaryReport uses) and one query for top
 * items, both scoped to `cash_session_id` rather than `created_at` — no
 * date arithmetic, no MerchantDay, because a session's boundary already
 * IS the boundary.
 */
class ZReportReport
{
    /**
     * @return array{
     *     orders_count: int,
     *     completed_count: int,
     *     pending_count: int,
     *     voided_count: int,
     *     gross_cents: int,
     *     discount_cents: int,
     *     net_cents: int,
     *     by_payment_method: array<string, array{count: int, amount_cents: int}>,
     * }
     */
    public function sales(CashSession $cashSession): array
    {
        $row = $cashSession->orders()
            ->toBase()
            ->selectRaw(
                <<<'SQL'
                    count(*) as orders_count,
                    count(*) filter (where status = ?) as completed_count,
                    count(*) filter (where status = ?) as pending_count,
                    count(*) filter (where status = ?) as voided_count,

                    -- Revenue figures: every non-voided order (pending +
                    -- completed), never voided — same rule as
                    -- SalesSummaryReport.
                    coalesce(sum(subtotal_cents) filter (where status != ?), 0) as gross_cents,
                    coalesce(sum(discount_cents) filter (where status != ?), 0) as discount_cents,
                    coalesce(sum(total_cents) filter (where status != ?), 0) as net_cents,

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
                    OrderStatus::Pending->value,
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

        // A session with zero orders still returns a row — every column is
        // a coalesced aggregate over an empty set, never a null row —
        // matching SalesSummaryReport's shape.
        //
        // by_payment_method.{cash,gcash}.amount_cents each fold in the
        // split orders' matching portion — a split's cash half lands in
        // `cash`, its gcash half in `gcash` — EXACTLY as
        // SalesSummaryReport does (see its docblock): the two reports
        // read the same orders and must never disagree about what a
        // split order contributed to each settlement channel.
        // `by_payment_method.split.amount_cents` still reports the same
        // money again under its own bucket (the order was, after all,
        // paid by splitting), it is only the cash/gcash buckets that
        // must never double each other.
        $cashAmountCents = (int) $row->cash_amount_cents + (int) $row->split_cash_amount_cents;
        $gcashAmountCents = (int) $row->gcash_amount_cents + (int) $row->split_gcash_amount_cents;

        return [
            'orders_count' => (int) $row->orders_count,
            'completed_count' => (int) $row->completed_count,
            'pending_count' => (int) $row->pending_count,
            'voided_count' => (int) $row->voided_count,
            'gross_cents' => (int) $row->gross_cents,
            'discount_cents' => (int) $row->discount_cents,
            'net_cents' => (int) $row->net_cents,
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
        ];
    }

    /**
     * Best sellers for this session alone — same grouping rule as
     * TopItemsReport (by the line's snapshot `product_name`, never
     * `product_id`; voided orders' lines excluded entirely) but scoped to
     * `cash_session_id` instead of a date range.
     *
     * @return list<array{product_name: string, quantity_sold: int, net_cents: int}>
     */
    public function topItems(CashSession $cashSession, int $limit = 10): array
    {
        $rows = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.cash_session_id', $cashSession->getKey())
            ->where('orders.status', '!=', OrderStatus::Voided->value)
            ->toBase()
            ->selectRaw(
                'order_items.product_name as product_name, '.
                'sum(order_items.quantity) as quantity_sold, '.
                'coalesce(sum(order_items.line_total_cents), 0) as net_cents',
            )
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity_sold')
            ->orderBy('order_items.product_name')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row): array => [
            'product_name' => (string) $row->product_name,
            'quantity_sold' => (int) $row->quantity_sold,
            'net_cents' => (int) $row->net_cents,
        ])->all();
    }
}
