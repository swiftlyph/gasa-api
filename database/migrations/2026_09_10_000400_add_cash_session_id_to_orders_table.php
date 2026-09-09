<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes a sale to the till session it was rung up in, so a session's
 * close can reconcile against exactly the orders that happened during it
 * rather than a time-window guess.
 *
 * Nullable, and existing orders stay null — NOT backfilled. Every order
 * before this phase was rung up with no session concept at all, so there
 * is no session a backfill could correctly assign one to; a guess (e.g.
 * "whichever session was open that day") would fabricate an audit trail
 * for sales that never went through one. A null `cash_session_id` means
 * "no session was open when this was rung up," which is simply true for
 * every pre-P4 row and remains a legitimate, expected value for any future
 * order rung up on a till nobody opened (see CheckoutAction — checkout
 * must never be blocked by a forgotten till).
 *
 * nullOnDelete, not cascade: an order is a financial record that must
 * survive its session being removed (sessions aren't deleted in practice,
 * but the FK should not be the reason an order could disappear if one
 * ever were).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('created_by_user_id')
                ->constrained()->nullOnDelete();

            $table->index(['merchant_id', 'cash_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'cash_session_id']);
            $table->dropConstrainedForeignId('cash_session_id');
        });
    }
};
