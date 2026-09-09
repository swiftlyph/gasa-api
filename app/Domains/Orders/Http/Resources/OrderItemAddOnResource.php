<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\OrderItemAddOn;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Add-ons carry no currency column of their own — they are always in the
 * order's currency, which is passed down from OrderResource rather than
 * guessed here.
 *
 * @mixin OrderItemAddOn
 */
class OrderItemAddOnResource extends JsonResource
{
    public function __construct(OrderItemAddOn $resource, private readonly string $currency)
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
            'name' => $this->name,
            'price_cents' => $this->price_cents,
            'price_formatted' => Money::format($this->price_cents, $this->currency),
        ];
    }
}
