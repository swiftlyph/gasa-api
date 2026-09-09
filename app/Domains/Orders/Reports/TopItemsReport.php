<?php

namespace App\Domains\Orders\Reports;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * GET /merchant/reports/top-items — best sellers in a date range, read
 * from `order_items`' SNAPSHOT columns.
 *
 * Grouped by `product_name` AS STORED ON THE LINE, never by `product_id`:
 * a line's name and price are what was actually sold, frozen at sale time
 * (see OrderItem's docblock). Grouping by product_id and joining back to
 * `products` for the current name would misreport a renamed product under
 * its new name for sales rung up under the old one, and break outright
 * for a deleted product (`product_id` is nullable, SET NULL on delete) —
 * both of which this report must survive by construction, not by luck.
 *
 * Voided orders' lines are excluded entirely — a canceled sale sold
 * nothing. Pending and completed both count, matching every other report
 * in this phase (the shop is paid at creation).
 */
class TopItemsReport
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    /**
     * @return list<array{product_name: string, quantity_sold: int, net_cents: int}>
     */
    public function generate(CarbonImmutable $fromUtc, CarbonImmutable $toUtcExclusive, int $limit): array
    {
        // Started from Order::query(), not OrderItem::query()->join(...):
        // order_items carries no merchant_id and no BelongsToMerchant scope
        // of its own (tenancy is inherited structurally through order_id —
        // see OrderItem's docblock), so the tenant filter MUST come from
        // Order's global scope here rather than being hand-rolled. Starting
        // from OrderItem and joining orders in would build a query with no
        // tenant boundary at all until someone remembered to add one.
        $rows = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $fromUtc)
            ->where('orders.created_at', '<', $toUtcExclusive)
            ->where('orders.status', '!=', OrderStatus::Voided->value)
            ->toBase()
            ->selectRaw(
                'order_items.product_name as product_name, '.
                'sum(order_items.quantity) as quantity_sold, '.
                'coalesce(sum(order_items.line_total_cents), 0) as net_cents',
            )
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity_sold')
            // Tie-broken by name so a repeated request returns the same
            // page in the same order — an unstable sort on a tie could
            // otherwise reorder the tenth and eleventh sellers between two
            // identical requests.
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
