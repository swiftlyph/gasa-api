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
 * "Merchant-local" is config('merchant.day_timezone') for now — a
 * dedicated setting, deliberately NOT config('app.timezone'). The two used
 * to be conflated here, which was the bug: app.timezone drives PHP's date
 * functions and correctly stays UTC for logging/storage, but "UTC" is not
 * where the shop is. A merchant in UTC+8 asking for "today" got UTC's
 * today instead of theirs, and orders placed after local midnight but
 * before UTC midnight landed under the wrong calendar day everywhere this
 * class is consulted.
 *
 * When merchants get their own timezone column, timezone() below is the
 * one thing that changes — which is only true because the boundary maths
 * lives here rather than being inlined at each call site.
 *
 * A HALF-OPEN range, always: >= start, < the next day's start. Never
 * whereDate() (it wraps the column in a function and discards the
 * (merchant_id, status, created_at) index) and never an inclusive
 * BETWEEN (which double-counts anything landing exactly at midnight).
 *
 * A SECOND thing has to be got right, easy to miss and the actual shape
 * of the bug this class was written to fix: every `timestamp` column this
 * class's boundaries are compared against (`created_at`, `opened_at`) is
 * `timestamp WITHOUT TIME ZONE`, storing naive UTC wall-clock digits —
 * app.timezone is UTC, so that is what plain `now()` writes. A Carbon
 * instance built in `Asia/Manila` binds into SQL as its OWN wall-clock
 * digits with no offset (`(string) $carbon` / `toDateTimeString()` — this
 * is how the query grammar stringifies a bound parameter), and Postgres
 * then compares two naive strings literally. Binding a Manila boundary
 * straight into a query silently compares it against UTC values as if it
 * were already UTC, which reintroduces exactly the bug this class exists
 * to prevent — just one layer further in. forQuery() below is the fix:
 * every boundary is converted to its UTC instant before it reaches SQL,
 * while startOf()/startOfToday() keep returning the merchant-local instant
 * callers reason about and display.
 */
class MerchantDay
{
    public static function timezone(): string
    {
        return (string) config('merchant.day_timezone');
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
     * The same instant as $merchantLocal, converted to UTC.
     *
     * REQUIRED before a value from this class touches a `timestamp`
     * column in ANY capacity — a query binding (`where('created_at', ...)`)
     * or a model attribute (`Order::create(['created_at' => ...])`,
     * `->update([...])`, a factory state). Both Eloquent's date casting and
     * the query grammar stringify a Carbon by its OWN wall-clock digits
     * with no offset, so a Manila-zoned instant written straight into
     * `created_at` is stored as if 23:58 Manila were 23:58 UTC — silently
     * six-to-nine hours wrong, with no error anywhere. See the class
     * docblock. This is not optional plumbing; skipping it reintroduces
     * the exact bug this class exists to prevent, one layer further in.
     */
    public static function forQuery(CarbonImmutable $merchantLocal): CarbonImmutable
    {
        return $merchantLocal->setTimezone('UTC');
    }

    /**
     * Restricts a query to the single day beginning at $dayStart
     * (merchant-local — converted to UTC here before binding).
     *
     * @param  Builder<Order>  $query
     */
    public static function constrain(Builder $query, CarbonImmutable $dayStart): void
    {
        $query->where('created_at', '>=', self::forQuery($dayStart))
            ->where('created_at', '<', self::forQuery($dayStart->addDay()));
    }
}
