<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11: an invite no longer has to belong to a merchant.
 *
 * `POST /admin/users` creates users for every audience — platform admins
 * and company admins included — and each needs the same invite to set
 * their own password. The NOT NULL here was an assumption from P7 (when
 * the only invite came from POST /merchant/team), never an invariant:
 * AcceptInviteAction resolves purely by `token_hash` and checks
 * used/expired, and reads `merchant_id` nowhere at all.
 *
 * One invite table rather than a parallel platform_invitations: expiry,
 * single-use, SHA-256-at-rest and rate-limiting are then one
 * implementation to audit instead of two, and the less-travelled of two
 * paths is exactly where such a bug would survive unnoticed.
 *
 * A null merchant_id means "not a merchant team invite" — it does NOT
 * mean the invite is unscoped. Nothing about redemption depends on this
 * column; the token itself is the authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created for non-merchant users have no merchant to point
        // at, so they are removed rather than guessed at — restoring the
        // constraint with invented ids would be worse than losing an
        // unredeemed invite, which the user can simply be re-sent.
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable(false)->change();
        });
    }
};
