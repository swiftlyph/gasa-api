<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes the orders status index for the kitchen queue.
 *
 * The queue is polled every few seconds by every kitchen screen in the
 * shop, and its query is always the same shape:
 *
 *     WHERE merchant_id = ? AND status = 'pending'
 *       AND created_at >= ? AND created_at < ?
 *     ORDER BY created_at, id
 *
 * (merchant_id, status) could satisfy the first two columns and then had
 * to filter and sort the rest by hand. (merchant_id, status, created_at)
 * satisfies the range and supplies the ordering already sorted, which on
 * a polled endpoint is the difference between a cheap index scan and a
 * sort of every pending order, several times a minute, forever.
 *
 * The old two-column index is DROPPED rather than left alongside: it is a
 * strict prefix of the new one, so every query it could serve the new one
 * serves at least as well. Keeping both would mean a second index to
 * write on every checkout and every transition, for nothing.
 *
 * (merchant_id, created_at) stays — the orders list filters by date with
 * no status, which the new index cannot serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['merchant_id', 'status', 'created_at']);
            $table->dropIndex(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['merchant_id', 'status']);
            $table->dropIndex(['merchant_id', 'status', 'created_at']);
        });
    }
};
