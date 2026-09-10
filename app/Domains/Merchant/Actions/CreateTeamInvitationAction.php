<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates a one-time invite token for a newly-added team member and
 * stores only its SHA-256 hash — see the team_invitations migration.
 *
 * No frontend-URL config exists yet to build a real link from: the only
 * frontend-adjacent config in this repo is FRONTEND_ORIGINS
 * (config/cors.php), which is a comma-separated CORS allowlist of
 * origins, not a single canonical frontend base URL to build a path
 * against — picking one of several allowed origins arbitrarily would be
 * wrong as often as right. Rather than invent a new config key this
 * Action isn't asked to add, `invite_url` is built as a relative path
 * (`/accept-invite?token=...`) when none is configured, and the caller
 * (dev/local logging, and the controller's dev-only response) is
 * expected to prefix it with whatever origin makes sense for them. If a
 * `app.frontend_url` config value is later added, it is used instead.
 *
 * Never sends email. The plaintext token is only ever held in this
 * method's local variables and its return value — nothing persists it.
 */
class CreateTeamInvitationAction
{
    /**
     * @return array{token: string, expires_at: Carbon, invite_url: string}
     */
    public function execute(Merchant $merchant, User $user): array
    {
        $plaintext = Str::random(40);
        $hash = hash('sha256', $plaintext);
        $expiresAt = now()->addHours(72);

        TeamInvitation::query()->create([
            'merchant_id' => $merchant->getKey(),
            'user_id' => $user->getKey(),
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        $base = config('app.frontend_url');
        $path = '/accept-invite?token='.$plaintext;
        $url = is_string($base) && $base !== '' ? rtrim($base, '/').$path : $path;

        // Dev-only: never log a usable credential-equivalent token outside
        // local/development environments, matching the spec's "do not send
        // real email — return/log the invite link in local/dev only."
        if (app()->environment(['local', 'development'])) {
            Log::info('Team invite created', [
                'merchant_id' => $merchant->getKey(),
                'user_id' => $user->getKey(),
                'invite_url' => $url,
            ]);
        }

        return [
            'token' => $plaintext,
            'expires_at' => $expiresAt,
            'invite_url' => $url,
        ];
    }
}
