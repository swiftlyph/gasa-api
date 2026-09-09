<?php

namespace App\Domains\Orders\Support;

use App\Domains\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * "A calendar day, as the shop experiences it" — and the single place
 * that decides what that means.
 *
 * Two endpoints now ask the question: the orders list (`?date=`) and the
 * kitchen queue (today by default). They MUST agree, or a merchant
 * looking at yesterday's takings and a kitchen screen looking at today's
 * queue will disagree about which orders belong to which day, at exactly
 * one moment: around midnight, when nobody is looking closely.
 *
 * "Merchant-local" is the app timezone for now. When merchants get their
 * own timezone column, this class is the one thing that changes — which
 * is only true because the boundary maths lives here rather than being
 * inlined at each call site.
 *
 * A HALF-OPEN range, always: >= start, < the next day's start. Never
 * whereDate() (it wraps the column in a function and discards the
 * (merchant_id, status, created_at) index) and never an inclusive
 * BETWEEN (which double-counts anything landing exactly at midnight).
 */
class MerchantDay
{
    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    /**
     * @param  string  $date  A calendar day as `Y-m-d`.
     */
    public static function startOf(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, self::timezone())
            ->startOfDay();
    }

    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * Restricts a query to the single day beginning at $dayStart.
     *
     * @param  Builder<Order>  $query
     */
    public static function constrain(Builder $query, CarbonImmutable $dayStart): void
    {
        $query->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayStart->addDay());
    }
}
