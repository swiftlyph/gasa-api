<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per checkout attempt that carried an Idempotency-Key header.
 *
 * The problem it solves is physical, not theoretical: the POS runs on
 * tablets over shop wifi. A cashier double-taps "charge", or the response
 * to a successful checkout is lost and the tablet retries — and the
 * customer is charged twice for one coffee. The key lets the server
 * recognise the second request as the same request.
 *
 * UNIQUE (merchant_id, key), never UNIQUE (key) alone. Two things follow
 * from that, both deliberate:
 *
 *  - Two merchants can independently generate the same UUID (or the same
 *    lazy "1") without one shop's checkout being answered with the other
 *    shop's order. Combined with BelongsToMerchant on the model, a key
 *    from merchant A is simply invisible to merchant B.
 *  - The index is the CONCURRENCY ARBITER. Two simultaneous requests with
 *    the same key both try to insert; Postgres lets exactly one through
 *    and the loser catches the violation and returns the winner's order.
 *    No advisory locks, no read-then-write race window.
 *
 * `order_id` is nullable only for the instant between reserving the key
 * and stamping the order onto it — both inside one transaction, so a
 * COMMITTED row always has an order. If the reservation is rolled back
 * (an invalid basket, say) the row disappears with it, which is what
 * makes a failed checkout not burn the key.
 *
 * There is no `updated_at`: a row is written once and then only read or
 * pruned. `created_at` is what retention is measured from — see
 * PruneCheckoutIdempotencyKeysCommand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Client-generated, opaque to us. Stored as text rather than
            // parsed as a UUID so a client using some other scheme still
            // gets protection instead of a validation error.
            $table->string('key');

            // Which cashier's attempt this was. Restrict-on-delete by
            // omission, matching orders.created_by_user_id.
            $table->foreignId('user_id')->constrained('users');

            // SHA-256 of the normalised request body (see
            // App\Domains\Orders\Support\CheckoutFingerprint) — 64 hex
            // characters. Comparing this is what separates "the same
            // request again" from "a different request reusing the key".
            $table->char('request_fingerprint', 64);

            // Cascade, NOT nullOnDelete: a key pointing at a deleted order
            // would be a row that can neither replay nor be re-used, and
            // the replay path would have to invent an answer for it. If
            // the order goes, the key goes, and the next attempt is simply
            // treated as new. (Orders are never deleted in practice — see
            // README § Orders — so this is a safety property, not a flow.)
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            // What the original attempt answered. Always 201 today; stored
            // rather than assumed so that if a future phase ever replays a
            // non-created outcome, the record already says which.
            $table->smallInteger('response_status')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['merchant_id', 'key']);

            // Retention sweeps delete by age across all merchants.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_idempotency_keys');
    }
};
