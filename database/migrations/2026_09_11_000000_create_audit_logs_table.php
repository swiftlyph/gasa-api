<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A general-purpose, append-only record of platform-admin actions —
     * deliberately NOT tenant-scoped (no merchant_id, no BelongsToMerchant):
     * it spans merchants by design, e.g. "which admin approved merchant X"
     * has no single tenant to scope it to. That is exactly why it must only
     * ever be read through admin.api routes — see App\Domains\Platform\
     * Models\AuditLog's docblock.
     *
     * Immutable: no `updated_at`. An audit entry is written once by
     * App\Domains\Platform\Actions\RecordAuditLogAction and never touched
     * again.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Restrict-on-delete by omission, matching merchants.owner_user_id:
            // an admin user can't be hard-deleted out from under their own
            // audit trail.
            $table->foreignId('actor_user_id')->constrained('users');

            $table->string('action');

            // The row this entry is about — e.g. a Merchant. Polymorphic
            // because audit entries will eventually cover more than one
            // subject type (users, future admin-managed resources).
            $table->morphs('subject');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Free-form context, e.g. { "reason": "policy violation" } on a
            // status change — never a substitute for old_values/new_values.
            $table->json('context')->nullable();

            $table->string('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // subject_type/subject_id already get a combined index from
            // morphs(); actor_user_id gets its own for "everything this
            // admin has done".
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
