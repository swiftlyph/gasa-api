<?php

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders. Counter-service semantics: an order is PAID at creation, so
 * there is no separate payment_status column — `status` alone is the
 * order's lifecycle (pending -> completed | voided). The audited system
 * carried both a status and a payment_status and they drifted apart into
 * states nobody could interpret; one status is the design here.
 *
 * `status` and `payment_method` are plain strings guarded by CHECK
 * constraints rather than native Postgres enum types, matching the
 * merchants-table precedent: ALTER TYPE gymnastics to add a value is
 * worse than widening a CHECK, and the PHP backed enums
 * (App\Domains\Orders\Enums\*) are the typed mirror. Adding a case to
 * either enum REQUIRES a migration widening the matching constraint.
 *
 * Orders are never deleted and never soft-deleted: a financial record
 * stays. `voided` IS the reversal mechanism, and it is deliberately
 * terminal — you reverse an order by voiding it, not by editing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Per-merchant sequential, e.g. ORD-000001. UNIQUE per merchant
            // (below), NOT globally: Merchant One and Merchant Two both
            // legitimately have an ORD-000001.
            $table->string('order_number');

            $table->string('status')->default(OrderStatus::Pending->value);

            // Every money column is integer CENTS. No floats, no decimals,
            // anywhere — currency travels alongside as its own column.
            $table->integer('subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->integer('total_cents');
            $table->char('currency', 3)->default('PHP');

            $table->string('payment_method');

            // Split payments only: how the total was divided. Null for a
            // pure cash or pure gcash order, so "not a split" and "a split
            // of zero" can't be confused.
            //
            // The rule that cash_cents + gcash_cents === total_cents for a
            // split (and that both are null otherwise) is enforced by a
            // CHECK constraint added in
            // 2026_09_09_050000_add_split_payment_check_to_orders_table —
            // deferred to that migration because P2's checkout is what
            // owns writing these columns.
            $table->integer('cash_cents')->nullable();
            $table->integer('gcash_cents')->nullable();

            // The cashier. The audited system had no such column, so once
            // an order was closed there was no way to answer "who rang
            // this up?" — which is the first question asked when a till is
            // short. Not nullable: an order always has an author.
            $table->foreignId('created_by_user_id')->constrained('users');

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users');

            $table->timestamps();

            $table->unique(['merchant_id', 'order_number']);

            // The two shapes the merchant order list is queried in: filtered
            // by status, and filtered by day. Both always tenant-first,
            // because the global scope always adds merchant_id.
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (%s))',
            $this->quoted(OrderStatus::values()),
        ));

        DB::statement(sprintf(
            'ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IN (%s))',
            $this->quoted(PaymentMethod::values()),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');
    }
};
