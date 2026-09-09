<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per merchant holding the last order number issued to them.
 *
 * This table is the reason order numbers can be both sequential AND
 * per-merchant. The alternatives that were rejected:
 *
 *   - MAX(order_number) + 1 / latest()->first(): two concurrent checkouts
 *     read the same maximum and issue the same number. Under real POS load
 *     (a queue of customers, two terminals) that is not a rare race.
 *   - A global sequence: merchants would see gaps ("where did orders 41-58
 *     go?") that leak other merchants' volume.
 *   - Random strings: unreadable over a counter, and no ordering.
 *
 * The counter is incremented under a row lock inside the order-creation
 * transaction — see App\Domains\Orders\Actions\GenerateOrderNumberAction,
 * which is the ONLY thing allowed to touch this table.
 *
 * merchant_id is the primary key: exactly one counter per merchant, no
 * surrogate id, so the lock target is unambiguous. Deliberately NOT using
 * BelongsToMerchant — this table is written through the query builder, and
 * a tenant-scoped model would fight the very transaction it runs inside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_order_counters', function (Blueprint $table) {
            $table->foreignId('merchant_id')->primary()->constrained()->cascadeOnDelete();

            // Starts at 0; the first order issued is 1 (ORD-000001). No
            // daily reset — numbering is continuous for the life of the
            // merchant, so a number identifies an order forever.
            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_order_counters');
    }
};
