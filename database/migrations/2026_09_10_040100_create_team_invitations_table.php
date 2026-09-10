<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time, expiring invite for a team member added via
 * POST /merchant/team. `token_hash` stores SHA-256 of the plaintext
 * token — never the token itself, matching how personal_access_tokens
 * never stores a plaintext Sanctum token either. The plaintext exists only
 * in the response/log at creation time and cannot be recovered from this
 * table afterwards.
 *
 * `used_at` rather than deleting the row on accept: an already-used token
 * must keep failing (422 invalid_invite) rather than looking like a
 * never-issued one, and the row is small, harmless history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
