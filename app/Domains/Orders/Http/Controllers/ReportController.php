<?php

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Orders\Http\Requests\ReportDateRangeRequest;
use App\Domains\Orders\Http\Requests\TopItemsReportRequest;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Reports\SalesByDayReport;
use App\Domains\Orders\Reports\SalesSummaryReport;
use App\Domains\Orders\Reports\TopItemsReport;
use App\Domains\Shared\Support\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Date-range reporting over orders. Every endpoint here is READ-ONLY —
 * these answer questions about sales that already happened, and compute
 * nothing that ever gets written back (Sanctum's last_used_at aside, which
 * is platform-wide and not specific to this controller).
 *
 * Session-scoped reporting (a true Z-report per cash session) lives in
 * App\Domains\CashSessions\Reports\ZReportReport / CashSessionController::
 * zReport() instead (P9) — see README § Shift report & receipt.
 * Everything below answers "what happened in this date range," never
 * "what happened in this till shift."
 *
 * All three actions share ReportDateRangeRequest for `from`/`to` — see its
 * docblock for the range rules and cap. Authorization goes through
 * OrderPolicy::viewReports (P8) rather than a second policy class of its
 * own: tenant ownership is the same "active merchant" question
 * OrderController already asks, gated additionally by its own
 * reports.view permission rather than orders.view (see OrderPolicy's
 * docblock — seeing sales totals is a distinct capability from seeing
 * individual orders).
 */
class ReportController extends Controller
{
    /**
     * GET /merchant/reports/sales-summary
     */
    public function salesSummary(ReportDateRangeRequest $request, SalesSummaryReport $report): JsonResponse
    {
        $this->authorize('viewReports', Order::class);

        [$fromUtc, $toUtcExclusive] = $request->queryRange();

        $summary = $report->generate($fromUtc, $toUtcExclusive);

        return response()->json([
            'orders_count' => $summary['orders_count'],
            'completed_count' => $summary['completed_count'],
            'voided_count' => $summary['voided_count'],

            'gross_cents' => $summary['gross_cents'],
            'gross_formatted' => Money::format($summary['gross_cents'], 'PHP'),
            'discount_cents' => $summary['discount_cents'],
            'discount_formatted' => Money::format($summary['discount_cents'], 'PHP'),
            'net_cents' => $summary['net_cents'],
            'net_formatted' => Money::format($summary['net_cents'], 'PHP'),

            // P10. `discount_cents` above still reports every peso off;
            // these say why, and decompose the range's sales for tax.
            'statutory_discount_cents' => $summary['statutory_discount_cents'],
            'statutory_discount_formatted' => Money::format($summary['statutory_discount_cents'], 'PHP'),
            'promo_discount_cents' => $summary['promo_discount_cents'],
            'promo_discount_formatted' => Money::format($summary['promo_discount_cents'], 'PHP'),
            'vatable_sales_cents' => $summary['vatable_sales_cents'],
            'vatable_sales_formatted' => Money::format($summary['vatable_sales_cents'], 'PHP'),
            'vat_cents' => $summary['vat_cents'],
            'vat_formatted' => Money::format($summary['vat_cents'], 'PHP'),
            'vat_exempt_sales_cents' => $summary['vat_exempt_sales_cents'],
            'vat_exempt_sales_formatted' => Money::format($summary['vat_exempt_sales_cents'], 'PHP'),
            'nonvat_sales_cents' => $summary['nonvat_sales_cents'],
            'nonvat_sales_formatted' => Money::format($summary['nonvat_sales_cents'], 'PHP'),

            'by_payment_method' => collect($summary['by_payment_method'])->map(fn (array $bucket): array => [
                'count' => $bucket['count'],
                'amount_cents' => $bucket['amount_cents'],
                'amount_formatted' => Money::format($bucket['amount_cents'], 'PHP'),
            ])->all(),

            'average_order_cents' => $summary['average_order_cents'],
            'average_order_formatted' => Money::format($summary['average_order_cents'], 'PHP'),
        ]);
    }

    /**
     * GET /merchant/reports/sales-by-day
     */
    public function salesByDay(ReportDateRangeRequest $request, SalesByDayReport $report): JsonResponse
    {
        $this->authorize('viewReports', Order::class);

        [$fromUtc, $toUtcExclusive] = $request->queryRange();

        $days = $report->generate($fromUtc, $toUtcExclusive, $request->fromDate(), $request->toDate());

        return response()->json([
            'data' => collect($days)->map(fn (array $day): array => [
                'date' => $day['date'],
                'orders_count' => $day['orders_count'],
                'net_cents' => $day['net_cents'],
                'net_formatted' => Money::format($day['net_cents'], 'PHP'),
            ])->all(),
        ]);
    }

    /**
     * GET /merchant/reports/top-items
     */
    public function topItems(TopItemsReportRequest $request, TopItemsReport $report): JsonResponse
    {
        $this->authorize('viewReports', Order::class);

        [$fromUtc, $toUtcExclusive] = $request->queryRange();

        $items = $report->generate($fromUtc, $toUtcExclusive, $request->limit());

        return response()->json([
            'data' => collect($items)->map(fn (array $item): array => [
                'product_name' => $item['product_name'],
                'quantity_sold' => $item['quantity_sold'],
                'net_cents' => $item['net_cents'],
                'net_formatted' => Money::format($item['net_cents'], 'PHP'),
            ])->all(),
        ]);
    }
}
