<?php

namespace App\Domains\Orders\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues the next per-merchant order number: ORD-000001, ORD-000002, ...
 *
 * The ONLY writer of `merchant_order_counters`. Used by the Order factory
 * today and by P2's checkout tomorrow, so both go through the same
 * guarantee rather than the factory quietly inventing numbers a different
 * way from production.
 *
 * How it stays correct under concurrent checkouts:
 *
 *   1. insertOrIgnore creates the merchant's counter row if this is their
 *      first order. On Postgres that compiles to ON CONFLICT DO NOTHING,
 *      so two terminals racing on a merchant's very first sale can't both
 *      insert — one wins, the other no-ops, neither errors.
 *   2. SELECT ... FOR UPDATE takes a row lock on that counter. A second
 *      transaction reaching this line blocks until the first commits, so
 *      the two never read the same last_number.
 *   3. The UPDATE and the caller's INSERT INTO orders commit together, so
 *      a number is only ever consumed by an order that actually exists.
 *
 * Step 3 is why this REQUIRES an open transaction rather than opening its
 * own: a lock released before the order row is written would let the next
 * caller take the same number. Calling it outside a transaction is a
 * programming error, not a runtime condition, hence the LogicException —
 * it must fail on a developer's first run, not silently produce duplicate
 * numbers in production months later.
 *
 * Rejected alternatives, all of which the audited system used at some
 * point: MAX(order_number) + 1 and latest()->first() (two concurrent
 * reads see the same maximum and issue the same number), a global
 * sequence (gaps leak other merchants' volume), and random strings
 * (unreadable at a counter, unordered).
 */
class GenerateOrderNumberAction
{
    private const PREFIX = 'ORD-';

    private const PAD_LENGTH = 6;

    public function execute(int $merchantId): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'GenerateOrderNumberAction must run inside a transaction: the counter lock has to be '
                .'held until the order row it numbers is committed.',
            );
        }

        $now = now();

        DB::table('merchant_order_counters')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $last = (int) DB::table('merchant_order_counters')
            ->where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->value('last_number');

        $next = $last + 1;

        DB::table('merchant_order_counters')
            ->where('merchant_id', $merchantId)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return $this->format($next);
    }

    /**
     * ORD-000001. Numbers past 999999 simply get longer rather than
     * wrapping or truncating — a seventh digit is better than a collision.
     */
    public function format(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}
