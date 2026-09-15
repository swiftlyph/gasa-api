<?php

namespace App\Domains\CashSessions\Http\Resources;

use App\Domains\CashSessions\Actions\ReconcileCashSessionAction;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Reports\ZReportReport;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * GET /merchant/cash-sessions/{cashSession}/z-report — one printable
 * summary of a till shift: the session's identity, its sales (attributed
 * by `cash_session_id`, never by date — see ZReportReport), its top
 * sellers, and the SAME cash reconciliation block
 * ReconcileCashSessionAction computes for GET .../{cashSession} — reused,
 * not re-derived (see that Action's docblock for why two implementations
 * of a money formula must never coexist).
 *
 * Works on an OPEN session (every figure computed live, right now) exactly
 * as it does on a CLOSED one (sales/top-items are recomputed from
 * immutable order data and return the same answer every time; the
 * reconciliation block's expected/counted/variance are the values FROZEN
 * at close — see ReconcileCashSessionAction and CashSessionResource).
 *
 * Orders with a NULL cash_session_id are never part of any Z-report — see
 * README § Reporting. They only exist when a shop sold with no drawer
 * open, and P6's date-range reports still capture them.
 *
 * @mixin CashSession
 */
class ZReportResource extends JsonResource
{
    public function __construct(CashSession $resource, private readonly int $topItemsLimit = 10)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ZReportReport $report */
        $report = app(ZReportReport::class);

        $sales = $report->sales($this->resource);
        $topItems = $report->topItems($this->resource, $this->topItemsLimit);

        return [
            'session' => [
                'id' => $this->id,
                'register_id' => $this->register_id,
                'register_name' => $this->whenLoaded('register', fn () => $this->register->name),
                'opened_by_user_id' => $this->opened_by_user_id,
                'opened_at' => $this->opened_at->toISOString(),
                'closed_by_user_id' => $this->closed_by_user_id,
                'closed_at' => $this->closed_at?->toISOString(),
                'status' => $this->status->value,
            ],

            'float' => [
                'opening_float_cents' => $this->opening_float_cents,
                'opening_float_formatted' => Money::format($this->opening_float_cents, 'PHP'),
            ],

            'sales' => [
                'orders_count' => $sales['orders_count'],
                'completed_count' => $sales['completed_count'],
                'pending_count' => $sales['pending_count'],
                'voided_count' => $sales['voided_count'],

                'gross_cents' => $sales['gross_cents'],
                'gross_formatted' => Money::format($sales['gross_cents'], 'PHP'),
                'discounts_cents' => $sales['discount_cents'],
                'discounts_formatted' => Money::format($sales['discount_cents'], 'PHP'),
                'net_cents' => $sales['net_cents'],
                'net_formatted' => Money::format($sales['net_cents'], 'PHP'),

                // P10. `discounts_cents` above still reports every peso
                // off; these break it down and decompose the shift's
                // sales for tax. Additive — nothing above changed.
                'statutory_discount_cents' => $sales['statutory_discount_cents'],
                'statutory_discount_formatted' => Money::format($sales['statutory_discount_cents'], 'PHP'),
                'promo_discount_cents' => $sales['promo_discount_cents'],
                'promo_discount_formatted' => Money::format($sales['promo_discount_cents'], 'PHP'),
                'vatable_sales_cents' => $sales['vatable_sales_cents'],
                'vatable_sales_formatted' => Money::format($sales['vatable_sales_cents'], 'PHP'),
                'vat_cents' => $sales['vat_cents'],
                'vat_formatted' => Money::format($sales['vat_cents'], 'PHP'),
                'vat_exempt_sales_cents' => $sales['vat_exempt_sales_cents'],
                'vat_exempt_sales_formatted' => Money::format($sales['vat_exempt_sales_cents'], 'PHP'),
                'nonvat_sales_cents' => $sales['nonvat_sales_cents'],
                'nonvat_sales_formatted' => Money::format($sales['nonvat_sales_cents'], 'PHP'),

                'by_payment_method' => collect($sales['by_payment_method'])->map(fn (array $bucket): array => [
                    'count' => $bucket['count'],
                    'amount_cents' => $bucket['amount_cents'],
                    'amount_formatted' => Money::format($bucket['amount_cents'], 'PHP'),
                ])->all(),
            ],

            'top_items' => collect($topItems)->map(fn (array $item): array => [
                'product_name' => $item['product_name'],
                'quantity_sold' => $item['quantity_sold'],
                'net_cents' => $item['net_cents'],
                'net_formatted' => Money::format($item['net_cents'], 'PHP'),
            ])->all(),

            'cash' => $this->cashBlock(),

            'movements' => $this->whenLoaded(
                'movements',
                fn () => CashMovementResource::collection($this->movements),
            ),
            'remittances' => $this->whenLoaded(
                'remittances',
                fn () => CashRemittanceResource::collection($this->remittances),
            ),

            'generated_at' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * The reconciliation block, EXACTLY as ReconcileCashSessionAction
     * computes it — reused wholesale rather than re-derived, the same
     * pattern CashSessionResource already follows (see its docblock).
     * `counted_cash_cents`/`variance_cents` stay null on an open session
     * and are the values frozen at close on a closed one.
     *
     * @return array<string, mixed>
     */
    private function cashBlock(): array
    {
        $figures = app(ReconcileCashSessionAction::class)->execute($this->resource);

        $block = [
            'cash_sales_gross_cents' => $figures['cash_sales_cents'],
            'cash_sales_gross_formatted' => Money::format($figures['cash_sales_cents'], 'PHP'),
            'voided_cash_cents' => $figures['voided_cash_cents'],
            'voided_cash_formatted' => Money::format($figures['voided_cash_cents'], 'PHP'),
            'cash_in_cents' => $figures['cash_in_cents'],
            'cash_in_formatted' => Money::format($figures['cash_in_cents'], 'PHP'),
            'cash_out_cents' => $figures['cash_out_cents'],
            'cash_out_formatted' => Money::format($figures['cash_out_cents'], 'PHP'),
            'confirmed_remittances_cents' => $figures['confirmed_remittances_cents'],
            'confirmed_remittances_formatted' => Money::format($figures['confirmed_remittances_cents'], 'PHP'),
        ];

        if ($this->isOpen()) {
            return [
                ...$block,
                'expected_cash_cents' => $figures['expected_cash_cents'],
                'expected_cash_formatted' => Money::format($figures['expected_cash_cents'], 'PHP'),
                'counted_cash_cents' => null,
                'counted_cash_formatted' => null,
                'variance_cents' => null,
                'variance_formatted' => null,
            ];
        }

        // Closed: expected/counted/variance are the values FROZEN at
        // close, not the ones just recomputed above — matching
        // CashSessionResource::reconciliation() exactly.
        return [
            ...$block,
            'expected_cash_cents' => $this->expected_cash_cents,
            'expected_cash_formatted' => Money::format($this->expected_cash_cents, 'PHP'),
            'counted_cash_cents' => $this->counted_cash_cents,
            'counted_cash_formatted' => Money::formatNullable($this->counted_cash_cents, 'PHP'),
            'variance_cents' => $this->variance_cents,
            'variance_formatted' => Money::formatNullable($this->variance_cents, 'PHP'),
        ];
    }
}
