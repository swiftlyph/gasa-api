<?php

use App\Domains\CashSessions\Enums\CashMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cash ledger: one normalised row per movement, never appended text.
 *
 * The audited system appended cash movements to a single growing
 * free-text blob per day — unparseable by anything but a human, and
 * un-auditable at any scale. A row per movement is queryable, summable,
 * and can be joined to who made it and when, which is the entire point of
 * a reconciliation feature.
 *
 * `amount_cents` is always POSITIVE; `type` alone carries direction (see
 * CashMovementType::sign()). A signed amount column would let
 * ReconcileCashSessionAction get the direction wrong twice — once from
 * the sign and once from the type — and have the two silently disagree.
 */
return new class extends Migration
{
    private const TYPE_CONSTRAINT = 'cash_movements_type_check';

    private const AMOUNT_CONSTRAINT = 'cash_movements_amount_positive_check';

    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained();

            $table->string('type');
            $table->integer('amount_cents');
            $table->string('reason');

            $table->foreignId('created_by_user_id')->constrained('users');

            $table->timestamps();

            $table->index(['merchant_id', 'cash_session_id']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE cash_movements ADD CONSTRAINT %s CHECK (type IN (%s))',
            self::TYPE_CONSTRAINT,
            $this->quoted(CashMovementType::values()),
        ));

        DB::statement(sprintf(
            'ALTER TABLE cash_movements ADD CONSTRAINT %s CHECK (amount_cents > 0)',
            self::AMOUNT_CONSTRAINT,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
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
