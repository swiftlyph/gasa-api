<?php

namespace App\Domains\Orders\Reports;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * GET /merchant/reports/sales-by-day — one row per LOCAL calendar day in
 * the range, days with no sales present as a zero row rather than absent —
 * a chart built on rows that skip empty days silently lies about the gap.
 *
 * `net_cents` follows the same voided/pending rule as the summary: voided
 * orders contribute nothing, pending orders count as revenue.
 *
 * One aggregate query, grouped by the merchant-local calendar day —
 * `date_trunc('day', created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)` shifts
 * each row into merchant-local wall-clock time before truncating, so a
 * sale at 11:58 PM local groups under that local day even though its
 * created_at (naive UTC) may already read the next UTC date. Grouping in
 * PHP after pulling every order's created_at would defeat the entire
 * point of doing this as one query. Empty days are filled in AFTER the
 * query, in PHP, over a range that is at most MAX_RANGE_DAYS long — not a
 * second query, and bounded by the same cap that bounds the aggregate.
 */
class SalesByDayReport
{
    /**
     * @return list<array{date: string, orders_count: int, net_cents: int}>
     */
    public function generate(CarbonImmutable $fromUtc, CarbonImmutable $toUtcExclusive, CarbonImmutable $fromLocal, CarbonImmutable $toLocalInclusive): array
    {
        $rows = Order::query()
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtcExclusive)
            ->where('status', '!=', OrderStatus::Voided->value)
            ->toBase()
            ->selectRaw(
                "(created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as local_date, ".
                'count(*) as orders_count, '.
                'coalesce(sum(total_cents), 0) as net_cents',
                [MerchantDay::timezone()],
            )
            ->groupBy('local_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->local_date);

        return $this->fillGaps($rows, $fromLocal, $toLocalInclusive);
    }

    /**
     * @param  Collection<string, stdClass>  $rows
     * @return list<array{date: string, orders_count: int, net_cents: int}>
     */
    private function fillGaps(Collection $rows, CarbonImmutable $fromLocal, CarbonImmutable $toLocalInclusive): array
    {
        $days = [];
        $cursor = $fromLocal;

        while ($cursor->lte($toLocalInclusive)) {
            $key = $cursor->format('Y-m-d');
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'orders_count' => $row !== null ? (int) $row->orders_count : 0,
                'net_cents' => $row !== null ? (int) $row->net_cents : 0,
            ];

            $cursor = $cursor->addDay();
        }

        return $days;
    }
}
