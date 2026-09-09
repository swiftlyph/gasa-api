<?php

use App\Domains\CashSessions\Enums\CashSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One till-shift: opened with a float, closed with a count, reconciled in
 * between. Per REGISTER, not per calendar day — the audited system keyed
 * a single session on a globally-unique date, which cannot express two
 * tills open at once and conflates "the shop's day" with "one cashier's
 * shift," two things that are not the same fact.
 *
 * `expected_cash_cents`, `counted_cash_cents` and `variance_cents` are all
 * nullable and stay null until CLOSE. Expected cash is DERIVED — see
 * App\Domains\CashSessions\Actions\ReconcileCashSessionAction, the single
 * authority on that arithmetic — and is only ever snapshotted here at the
 * moment of closing, never maintained as a running total that could drift
 * from what the orders and movements actually say.
 *
 * THE PARTIAL UNIQUE INDEX is the real guard against two open sessions on
 * one register, not the 409 the controller returns. A composite unique
 * index can't express "unique among open rows only" — `UNIQUE
 * (register_id, status)` would also forbid a register ever having two
 * CLOSED sessions in its history, which is the normal case on day two. A
 * partial index (`WHERE status = 'open'`) is the only mechanism that
 * enforces "at most one of these" while leaving history unconstrained, so
 * a race between two opens on the same register is decided by Postgres,
 * not by a check-then-act read the application could lose.
 *
 * Postgres-only, matching the orders/merchants CHECK precedent — sqlite
 * cannot ALTER TABLE ADD CONSTRAINT or a partial CREATE INDEX, and the
 * test suite runs on real Postgres, so this constraint is genuinely
 * exercised (see tests/Feature/CashSessions/CashSessionTest.php's "the
 * database rejects a second open session" case).
 */
return new class extends Migration
{
    private const STATUS_CONSTRAINT = 'cash_sessions_status_check';

    private const OPEN_INDEX = 'cash_sessions_one_open_per_register';

    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained();

            $table->foreignId('opened_by_user_id')->constrained('users');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users');

            $table->string('status')->default(CashSessionStatus::Open->value);

            // Every money column here is integer CENTS, matching orders.
            $table->integer('opening_float_cents');
            $table->integer('counted_cash_cents')->nullable();
            $table->integer('expected_cash_cents')->nullable();
            $table->integer('variance_cents')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // The two shapes the session history is queried in: by status,
            // and by register, tenant-first as always.
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'register_id']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE cash_sessions ADD CONSTRAINT %s CHECK (status IN (%s))',
            self::STATUS_CONSTRAINT,
            $this->quoted(CashSessionStatus::values()),
        ));

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON cash_sessions (register_id) WHERE status = %s',
            self::OPEN_INDEX,
            "'".CashSessionStatus::Open->value."'",
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
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
