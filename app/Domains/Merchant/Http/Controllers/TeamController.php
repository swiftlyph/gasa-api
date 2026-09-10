<?php

namespace App\Domains\Merchant\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\AddTeamMemberAction;
use App\Domains\Merchant\Actions\RemoveTeamMemberAction;
use App\Domains\Merchant\Actions\UpdateTeamMemberRoleAction;
use App\Domains\Merchant\Http\Requests\AddTeamMemberRequest;
use App\Domains\Merchant\Http\Requests\UpdateTeamMemberRequest;
use App\Domains\Merchant\Http\Resources\TeamMemberResource;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Policies\TeamMemberPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/POST /merchant/team, PATCH/DELETE /merchant/team/{user} — team
 * members and invitations for the caller's own merchant.
 *
 * AUTHORIZATION:
 *
 * P8: every method here now calls TeamMemberPolicy explicitly —
 * viewAny() for index(), create() for store(), update()/delete()
 * unchanged — because role now matters (team.view / team.manage), not
 * only "does this user have an active merchant." All four calls go
 * through the container directly (`app(TeamMemberPolicy::class)->...`),
 * deliberately NOT via $this->authorize(...): Laravel resolves an
 * authorize() call for viewAny/create by the MODEL CLASS name
 * (Merchant::class), and MerchantPolicy is already the auto-discovered
 * policy for Merchant::class (see its docblock) — going through
 * $this->authorize() here would silently invoke MerchantPolicy instead
 * of TeamMemberPolicy for every one of these abilities, not just
 * update/delete. Calling the container-resolved policy directly avoids
 * that collision uniformly across all four methods.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! app(TeamMemberPolicy::class)->viewAny($user)) {
            throw new AuthorizationException;
        }

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        // Stable, documented order — oldest-added first, tiebroken by the
        // pivot's own primary ordering via merchant_user.created_at,
        // matching RegisterController::index()'s oldest('id') reasoning:
        // an unstable sort can show the same row twice across requests.
        $members = $merchant->users()->orderBy('merchant_user.created_at')->get();

        $data = $members
            ->map(fn (User $member) => (new TeamMemberResource($member, $merchant))->resolve())
            ->values();

        // Explicit { data: [...] } envelope, matching RegisterController
        // and every other unpaginated list endpoint (README § Response
        // shapes).
        return response()->json(['data' => $data]);
    }

    public function store(AddTeamMemberRequest $request, AddTeamMemberAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! app(TeamMemberPolicy::class)->create($user)) {
            throw new AuthorizationException;
        }

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $result = $action->execute($merchant, $request->payload());

        /** @var User $member */
        $member = $result['user'];
        $invitation = $result['invitation'];

        $payload = [
            'id' => $member->id,
            'name' => $member->name,
            'email' => (string) $member->email,
            'role_in_merchant' => $request->payload()['role_in_merchant'],
            'is_owner' => false,
            'created_at' => $member->created_at?->toISOString(),
        ];

        // Dev-only: the plaintext invite token/link is never returned in
        // production — see CreateTeamInvitationAction's docblock. Never
        // sent by real email this phase.
        if (app()->environment(['local', 'development'])) {
            $payload['invite'] = [
                'token' => $invitation['token'],
                'expires_at' => $invitation['expires_at']->toISOString(),
                'url' => $invitation['invite_url'],
            ];
        }

        return response()->json($payload, JsonResponse::HTTP_CREATED);
    }

    public function update(
        UpdateTeamMemberRequest $request,
        User $user,
        UpdateTeamMemberRoleAction $action,
    ): TeamMemberResource {
        /** @var User $actingUser */
        $actingUser = $request->user();

        /** @var Merchant $merchant */
        $merchant = $actingUser->merchant();

        // {user} is route-model-bound directly to User, which is not
        // BelongsToMerchant-scoped (User has no merchant_id column of its
        // own) — so cross-tenant membership must be verified explicitly
        // here. Always 404, never 403: a 403 would confirm the row exists
        // for another merchant, exactly the leak TenantLeakageTest guards
        // against for every other tenant-owned resource.
        if (! $merchant->users()->where('users.id', $user->id)->exists()) {
            throw new ModelNotFoundException;
        }

        if (! app(TeamMemberPolicy::class)->update($actingUser, $merchant)) {
            throw new AuthorizationException;
        }

        $action->execute($merchant, $user, $request->payload()['role_in_merchant']);

        $member = $merchant->users()->where('users.id', $user->id)->first();

        return new TeamMemberResource($member, $merchant);
    }

    public function destroy(Request $request, User $user, RemoveTeamMemberAction $action): JsonResponse
    {
        /** @var User $actingUser */
        $actingUser = $request->user();

        /** @var Merchant $merchant */
        $merchant = $actingUser->merchant();

        // Same tenant-membership 404 check as update() — see its comment.
        if (! $merchant->users()->where('users.id', $user->id)->exists()) {
            throw new ModelNotFoundException;
        }

        if (! app(TeamMemberPolicy::class)->delete($actingUser, $merchant)) {
            throw new AuthorizationException;
        }

        $action->execute($merchant, $user);

        // Mirrors AuthController::logout()'s confirmation shape/tone
        // exactly: a small 200 JSON body, never a bare 204.
        return response()->json(['message' => 'Removed from team.', 'code' => 'team_member_removed']);
    }
}
