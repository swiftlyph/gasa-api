<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket on the kitchen screen.
 *
 * A SEPARATE resource from OrderResource, not OrderResource with flags.
 * The two have different audiences and answer different questions: the
 * merchant's order list is a financial record, this is a work instruction.
 * Overloading one class with "include money?" switches is how a resource
 * ends up leaking the wrong fields to the wrong screen the first time
 * someone adds a parameter and forgets a caller.
 *
 * THERE IS NO MONEY HERE, deliberately. A barista does not need to know
 * what a drink cost, the kitchen screen is the most-displayed and
 * least-access-controlled surface in the shop, and the payload is polled
 * every few seconds — so every field that isn't needed is bytes on the
 * wire and one more thing on a screen the customer can see over the
 * counter.
 *
 * @mixin Order
 */
class KitchenOrderResource extends JsonResource
{
    /**
     * $now is passed in rather than read per-order so every ticket in one
     * response is measured from the SAME instant. Computing now() inside
     * the loop would let two orders created in the same second report
     * different waiting times, which on a screen sorted by age looks like
     * a bug.
     */
    public function __construct(Order $resource, private readonly CarbonImmutable $now)
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
            'order_number' => $this->order_number,
            'created_at' => $this->created_at->toISOString(),

            // Computed HERE, not by the client. A kitchen tablet's clock
            // can be minutes off, and "this order has been waiting 14
            // minutes" derived from a skewed clock is worse than useless —
            // it is confidently wrong, and it drives whether staff
            // apologise to a customer. The server owns elapsed time.
            'waiting_seconds' => $this->waitingSeconds(),

            'items' => $this->items->map($this->line(...))->values(),
        ];
    }

    /**
     * Clamped at zero: a row written by a machine whose clock is slightly
     * ahead would otherwise report a negative wait, and no ticket has been
     * waiting minus four seconds.
     */
    private function waitingSeconds(): int
    {
        return max(0, $this->now->getTimestamp() - $this->created_at->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     */
    private function line(OrderItem $item): array
    {
        return [
            // Included so a polling screen has a stable key to render and
            // diff against between polls, rather than re-keying on array
            // position and animating every ticket on every refresh.
            'id' => $item->id,

            // The SNAPSHOT name, as always — what was actually ordered,
            // even if the product has since been renamed or deleted.
            'product_name' => $item->product_name,
            'quantity' => $item->quantity,

            // Names only. The barista needs to know to add the shot, not
            // what it was charged for.
            'add_ons' => $item->addOns->pluck('name')->values(),
        ];
    }
}
