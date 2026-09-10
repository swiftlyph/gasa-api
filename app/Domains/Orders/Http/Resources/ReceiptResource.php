<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\Order;
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

                'payment_method' => $this->payment_method->value,
                'cash_cents' => $this->cash_cents,
                'cash_formatted' => Money::formatNullable($this->cash_cents, $currency),
                'gcash_cents' => $this->gcash_cents,
                'gcash_formatted' => Money::formatNullable($this->gcash_cents, $currency),
            ],

            'generated_at' => now()->toISOString(),
        ];
    }
}
