<?php

namespace App\Domains\Orders\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Orders\Exceptions\IdempotencyKeyReuse;
use App\Domains\Orders\Models\CheckoutIdempotencyKey;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\CheckoutFingerprint;
use App\Domains\Orders\Support\CheckoutResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wraps CheckoutAction so a repeated checkout attempt produces one order
 * instead of two.
 *
 * The POS runs on tablets over shop wifi. A double-tapped "charge", or a
 * successful checkout whose response never made it back, currently
 * becomes two orders and one coffee. A client-generated
 * `Idempotency-Key` header lets the server recognise the second request
 * as the same request.
 *
 * Four outcomes, in the order they are decided:
 *
 *  1. NO KEY — delegate straight through. Existing callers are completely
 *     unaffected; idempotency is opt-in per request.
 *  2. KEY ALREADY SEEN, same fingerprint — return the original order,
 *     creating nothing and never touching the order counter.
 *  3. KEY ALREADY SEEN, different fingerprint — 409. See
 *     IdempotencyKeyReuse for why that is loud rather than quiet.
 *  4. KEY UNSEEN — reserve it and check out, in ONE transaction.
 *
 * Two properties of case 4 are what make this actually safe, rather than
 * merely usually-safe:
 *
 * RESERVE FIRST, THEN SELL. The key row is inserted before CheckoutAction
 * runs, so a concurrent duplicate collides on the unique index
 * immediately — before any product lookup, and before a number is drawn
 * from the merchant's counter.
 *
 * ONE TRANSACTION, SO FAILURE UNRESERVES. If the basket turns out to be
 * invalid, the rollback takes the key reservation with it. The key is not
 * burned: the cashier fixes the order and retries with the same key,
 * exactly as they should be able to.
 *
 * The unique index is the only concurrency control here. A check-then-act
 * on the read below would have a window between the two; the insert has
 * none, because Postgres makes the second inserter wait on the first and
 * then rejects it.
 */
class IdempotentCheckoutAction
{
    public function __construct(
        private readonly CheckoutAction $checkout,
    ) {}

    /**
     * @param  array{
     *     payment_method: string,
     *     cash_cents?: int|null,
     *     gcash_cents?: int|null,
     *     discount_cents?: int|null,
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         add_ons?: list<array{name: string, price_cents: int}>
     *     }>
     * }  $payload  The checkout body, with the idempotency key already
     *              separated out (see CheckoutRequest::checkoutPayload()).
     */
    public function execute(?string $key, array $payload, User $cashier): CheckoutResult
    {
        if ($key === null) {
            return new CheckoutResult($this->checkout->execute($payload, $cashier), replayed: false);
        }

        $fingerprint = CheckoutFingerprint::for($payload);

        // Tenant-scoped by BelongsToMerchant, which is the whole of the
        // cross-merchant guarantee: merchant B asking about merchant A's
        // key value finds nothing here and falls through to a normal
        // checkout of their own.
        $seen = CheckoutIdempotencyKey::query()->where('key', $key)->first();

        if ($seen !== null) {
            return $this->replay($seen, $fingerprint);
        }

        try {
            return DB::transaction(fn (): CheckoutResult => $this->reserveAndCheckout(
                $key,
                $fingerprint,
                $payload,
                $cashier,
            ));
        } catch (UniqueConstraintViolationException) {
            // Lost the race: another request inserted the same
            // (merchant_id, key) between the read above and our insert.
            // Everything this transaction did — the reservation, the
            // order, the counter increment — has already rolled back, so
            // the winner's order is simply the answer to this request too.
            $winner = CheckoutIdempotencyKey::query()->where('key', $key)->first();

            if ($winner === null) {
                // The unique index fired but the row is not visible, which
                // should be impossible: the loser only sees a violation
                // once the winner has committed. Fail loudly rather than
                // silently checking out again, which would produce the
                // duplicate charge this class exists to prevent.
                throw new RuntimeException(
                    'Idempotency key collided but no committed row was found; refusing to re-check-out.',
                );
            }

            return $this->replay($winner, $fingerprint);
        }
    }

    /**
     * @param  array{payment_method: string, cash_cents?: int|null, gcash_cents?: int|null, discount_cents?: int|null, items: list<array{product_id: int, quantity: int, add_ons?: list<array{name: string, price_cents: int}>}>}  $payload
     */
    private function reserveAndCheckout(
        string $key,
        string $fingerprint,
        array $payload,
        User $cashier,
    ): CheckoutResult {
        // merchant_id is stamped by BelongsToMerchant from the
        // authenticated user — the same source CheckoutAction files the
        // order under, so a key and its order can never end up on
        // different tenants.
        $reservation = CheckoutIdempotencyKey::query()->create([
            'key' => $key,
            'user_id' => $cashier->getKey(),
            'request_fingerprint' => $fingerprint,
        ]);

        $order = $this->checkout->execute($payload, $cashier);

        $reservation->update([
            'order_id' => $order->getKey(),
            'response_status' => 201,
        ]);

        return new CheckoutResult($order, replayed: false);
    }

    /**
     * Answers a repeat request from a key that has already been used.
     */
    private function replay(CheckoutIdempotencyKey $seen, string $fingerprint): CheckoutResult
    {
        // hash_equals, not ===: this comparison decides between replaying
        // an order and rejecting the request, and it should not be
        // timing-variable.
        if (! hash_equals($seen->request_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyReuse;
        }

        /** @var Order|null $order */
        $order = $seen->order;

        if ($order === null) {
            // A committed key always has an order (they are written in one
            // transaction, and the FK cascades if the order is ever
            // deleted). Reaching here means the invariant broke, and
            // checking out again would double-charge — so refuse instead.
            throw new RuntimeException(
                "Idempotency key {$seen->getKey()} has no order; refusing to re-check-out.",
            );
        }

        return new CheckoutResult($order, replayed: true);
    }
}
