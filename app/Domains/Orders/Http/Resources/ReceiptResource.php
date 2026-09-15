<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderBeneficiary;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderItemAddOn;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /merchant/orders/{order}/receipt — data for printing a receipt; no
 * PDF, no printer driver, no email. A flat payload composing the
 * merchant's CURRENT profile (name, legal name, address, phone, tax id,
 * receipt header/footer) with the order's OWN stored snapshots (lines,
 * add-ons, totals, payment breakdown) — the frontend renders and prints
 * it.
 *
 * Snapshot discipline, read carefully: `merchant` fields are read from the
 * merchant AS IT IS NOW, not as it was at sale time — reprinting a receipt
 * after the shop edits its profile shows the CURRENT header, deliberately
 * (see README § Reporting / Receipts). Everything ELSE — order lines, unit
 * prices, add-ons, totals — comes from Order/OrderItem/OrderItemAddOn's
 * own snapshot columns, exactly like OrderResource, and never drifts
 * regardless of catalog changes since the sale.
 *
 * P10 adds the statutory block, which follows the ORDER's snapshot rule
 * rather than the merchant's: `tax.vat_registered` is what the shop was
 * at SALE TIME, read from the order's own frozen column, so a merchant
 * that registers for VAT next month does not retroactively add a VAT
 * block to last month's reprinted slips. The VAT lines are printed only
 * for an order that was VAT-registered; every other order prints the
 * `non_vat_note` instead, because "no VAT was charged" has to be stated
 * on the slip rather than inferred from four zeroes.
 *
 * A VOIDED order still returns 200 with this same shape, never a 404 — a
 * reprint of a voided slip is a normal, expected action (the customer
 * wants proof it was reversed), so `voided` and `voided_at` are surfaced
 * prominently rather than the endpoint refusing to answer.
 *
 * @mixin Order
 */
class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = $this->currency;
        $merchant = $this->merchant;

        return [
            'merchant' => [
                'name' => $merchant->name,
                'legal_name' => $merchant->legal_name,
                'address_line1' => $merchant->address_line1,
                'address_line2' => $merchant->address_line2,
                'city' => $merchant->city,
                'postal_code' => $merchant->postal_code,
                'phone' => $merchant->phone,
                'tax_identifier' => $merchant->tax_identifier,
                'receipt_header' => $merchant->receipt_header,
                'receipt_footer' => $merchant->receipt_footer,
            ],

            'order' => [
                'id' => $this->id,
                'order_number' => $this->order_number,
                'status' => $this->status->value,
                'created_at' => $this->created_at->toISOString(),

                // Prominent rather than buried in `status`: a client
                // rendering a receipt should be able to check one boolean
                // rather than string-compare a status value to decide
                // whether to stamp "VOIDED" across the slip.
                'voided' => $this->status->value === 'voided',
                'voided_at' => $this->voided_at?->toISOString(),

                'cashier_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),

                'lines' => $this->items->map(fn (OrderItem $item) => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'unit_price_formatted' => Money::format($item->unit_price_cents, $currency),
                    'line_total_cents' => $item->line_total_cents,
                    'line_total_formatted' => Money::format($item->line_total_cents, $currency),

                    // P10: what the statutory discount took off this line
                    // and what was actually owed for it. 0 / line_total on
                    // an ordinary line.
                    'discount_cents' => $item->discount_cents,
                    'discount_formatted' => Money::format($item->discount_cents, $currency),
                    'payable_cents' => $item->payable_cents,
                    'payable_formatted' => Money::format($item->payable_cents, $currency),

                    'add_ons' => $item->addOns->map(fn (OrderItemAddOn $addOn) => [
                        'name' => $addOn->name,
                        'price_cents' => $addOn->price_cents,
                        'price_formatted' => Money::format($addOn->price_cents, $currency),
                    ])->values(),
                ])->values(),

                'subtotal_cents' => $this->subtotal_cents,
                'subtotal_formatted' => Money::format($this->subtotal_cents, $currency),
                'discount_cents' => $this->discount_cents,
                'discount_formatted' => Money::format($this->discount_cents, $currency),
                'total_cents' => $this->total_cents,
                'total_formatted' => Money::format($this->total_cents, $currency),

                // P10: the statutory block. `discount_cents` above still
                // carries every peso off the order; these say why.
                'statutory_discount_cents' => $this->statutory_discount_cents,
                'statutory_discount_formatted' => Money::format($this->statutory_discount_cents, $currency),
                'promo_discount_cents' => $this->promo_discount_cents,
                'promo_discount_formatted' => Money::format($this->promo_discount_cents, $currency),

                'tax' => $this->taxBlock($currency),

                // One printed line per person who claimed a discount:
                // what they are, who they are, the ID they presented, and
                // what it saved them. All four print on the slip.
                'beneficiaries' => $this->beneficiaries->map(
                    fn (OrderBeneficiary $beneficiary) => [
                        'type' => $beneficiary->type->value,
                        'type_label' => $beneficiary->type->label(),
                        'name' => $beneficiary->name,
                        'id_number' => $beneficiary->id_number,
                        'discount_cents' => $beneficiary->discount_cents,
                        'discount_formatted' => Money::format($beneficiary->discount_cents, $currency),
                    ],
                )->values(),

                'payment_method' => $this->payment_method->value,
                'cash_cents' => $this->cash_cents,
                'cash_formatted' => Money::formatNullable($this->cash_cents, $currency),
                'gcash_cents' => $this->gcash_cents,
                'gcash_formatted' => Money::formatNullable($this->gcash_cents, $currency),
            ],

            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * The printed tax block, shaped by what the order ACTUALLY was — read
     * from its own snapshot columns, never from the live merchant.
     *
     * A VAT-registered order prints the VAT breakdown. Anything else
     * prints `non_vat_note` and no VAT lines at all: a slip for a shop
     * that charges no VAT must SAY so, rather than showing four zeroes a
     * customer would have to interpret. The `vat_registered` flag is
     * present in both shapes so a renderer can branch on one boolean.
     *
     * @return array<string, mixed>
     */
    private function taxBlock(string $currency): array
    {
        if (! $this->vat_registered_snapshot) {
            return [
                'vat_registered' => false,
                'non_vat_note' => 'This is a NON-VAT registered sale.',
                'nonvat_sales_cents' => $this->nonvat_sales_cents,
                'nonvat_sales_formatted' => Money::format($this->nonvat_sales_cents, $currency),
            ];
        }

        return [
            'vat_registered' => true,
            'vat_rate_bps' => $this->vat_rate_bps_snapshot,
            'vatable_sales_cents' => $this->vatable_sales_cents,
            'vatable_sales_formatted' => Money::format($this->vatable_sales_cents, $currency),
            'vat_cents' => $this->vat_cents,
            'vat_formatted' => Money::format($this->vat_cents, $currency),
            'vat_exempt_sales_cents' => $this->vat_exempt_sales_cents,
            'vat_exempt_sales_formatted' => Money::format($this->vat_exempt_sales_cents, $currency),
        ];
    }
}
