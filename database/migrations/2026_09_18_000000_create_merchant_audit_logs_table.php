<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only record of a merchant-portal team member's mutating
     * action — who did what, to which subject, within their own merchant.
     * Tenant-scoped (merchant_id, cascadeOnDelete like every other
     * BelongsToMerchant table), unlike the platform-admin `audit_logs`
     * table, which is deliberately unscoped and spans every merchant — see
     * App\Domains\Platform\Models\AuditLog's docblock for why that one
     * stays separate and this one must never be merged into it.
     *
     * Immutable: no `updated_at`. Written once by
     * App\Domains\Merchant\Actions\RecordMerchantAuditLogAction and never
     * touched again.
     */
    public function up(): void
    {
        Schema::create('merchant_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Restrict-on-delete by omission, matching audit_logs.actor_user_id:
            // a team member's own audit trail must survive their removal
            // from the team.
            $table->foreignId('actor_user_id')->constrained('users');

            $table->string('action');

            // The row this entry is about — e.g. an Order, CashSession,
            // Product, Ingredient, or User (for team changes). Polymorphic
            // because this table covers every mutating domain in the
            // merchant portal.
            $table->morphs('subject');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Free-form context, e.g. { "reason": "..." } — never a
            // substitute for old_values/new_values.
            $table->json('context')->nullable();

            $table->string('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The only listing query this table serves: "this merchant's
            // trail, newest first."
            $table->index(['merchant_id', 'created_at']);
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_audit_logs');
    }
};
