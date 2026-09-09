<?php

use App\Domains\CashSessions\Enums\RemittanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash physically taken out of the till and remitted upward (to a safe, a
 * bank drop, an office) — confirmed by a SECOND user, never the creator.
 *
 * The audited system let the same person who recorded a remittance also
 * confirm it, which makes "confirmed" mean nothing: segregation of duties
 * is the entire control a remittance record provides, and one person
 * cannot provide it alone. `confirmed_by_user_id` differing from
 * `created_by_user_id` is enforced in code (see
 * App\Domains\CashSessions\Actions\ConfirmRemittanceAction) rather than a
 * CHECK constraint, because the rule spans two nullable columns where one
 * starts empty — a constraint would have to special-case "not yet
 * confirmed" anyway, and the Action is where the 403 is raised regardless.
 *
 * `attachment_path` exists but is OUT OF SCOPE this phase: no upload
 * endpoint writes it, and it stays null on every row created here. Adding
 * it now rather than in a later migration means a later upload feature
 * only has to add behaviour, not schema, to a table other rows already
 * reference.
 */
return new class extends Migration
{
    private const STATUS_CONSTRAINT = 'cash_remittances_status_check';

    public function up(): void
    {
        Schema::create('cash_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained();

            $table->integer('amount_cents');
            $table->text('note')->nullable();

            // Out of scope this phase — see the class docblock. The column
            // exists so the upload feature only adds behaviour later.
            $table->string('attachment_path')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();

            $table->string('status')->default(RemittanceStatus::Pending->value);

            $table->timestamps();

            $table->index(['merchant_id', 'cash_session_id']);
            $table->index(['merchant_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE cash_remittances ADD CONSTRAINT %s CHECK (status IN (%s))',
            self::STATUS_CONSTRAINT,
            $this->quoted(RemittanceStatus::values()),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_remittances');
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
