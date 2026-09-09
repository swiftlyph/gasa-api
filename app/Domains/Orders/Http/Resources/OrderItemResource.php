<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\OrderItem;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A line as it was sold.
 *
 * `product_name` and `unit_price_cents` come from the item's own snapshot
 * columns — never from $this->product, which may have been renamed,
 * repriced, or deleted since. `product_id` is exposed anyway (nullable)
 * so a client can deep-link to a still-existing product, but it is a
 * link, not the source of what is displayed.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    public function __construct(OrderItem $resource, private readonly string $currency)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Null once the catalog entry is gone. The line below still
            // renders in full — that is the whole point of snapshotting.
            'product_id' => $this->product_id,

            'product_name' => $this->product_name,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'unit_price_formatted' => Money::format($this->unit_price_cents, $this->currency),
            'line_total_cents' => $this->line_total_cents,
            'line_total_formatted' => Money::format($this->line_total_cents, $this->currency),

            'add_ons' => $this->addOns->map(
                fn ($addOn) => new OrderItemAddOnResource($addOn, $this->currency),
            )->values(),
        ];
    }
}
