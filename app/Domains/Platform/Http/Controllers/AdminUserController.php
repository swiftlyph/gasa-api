<?php

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Actions\ChangeUserRoleAction;
use App\Domains\Platform\Actions\CreateUserAction;
use App\Domains\Platform\Actions\DeactivateUserAction;
use App\Domains\Platform\Actions\ResendUserInviteAction;
use App\Domains\Platform\Actions\RestoreUserAction;
use App\Domains\Platform\Http\Requests\ChangeUserRoleRequest;
use App\Domains\Platform\Http\Requests\CreateUserRequest;
use App\Domains\Platform\Http\Requests\IndexAdminUsersRequest;
use App\Domains\Platform\Http\Resources\AdminUserDetailResource;
use App\Domains\Platform\Http\Resources\AdminUserListResource;
use App\Domains\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform-admin user management across every audience — every route sits
 * behind admin.api (auth:sanctum + role:platform_admin +
 * AllowsAdminContext, see bootstrap/app.php). No policy layer: every
 * platform_admin may act on every user, so the route group IS the
 * authorization boundary, matching AdminMerchantController.
 *
 * The lockout and ownership guards are NOT here — they live in the
 * Actions, so they hold no matter which caller reaches them (controller,
 * console command, future bulk import).
 *
 * `{user}` binds with withTrashed() (see routes/api/v1/admin.php) so a
 * deactivated account can still be viewed and restored; the ordinary
 * binding would 404 the moment a user was deactivated.
 */
class AdminUserController extends Controller
{
    /**
     * A fixed number of queries regardless of match count: one count, one
     * page with `roles` and `merchants` eager-loaded. Both are needed by
     * AdminUserListResource, which never touches an unloaded relation.
     */
    public function index(IndexAdminUsersRequest $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->with(['roles', 'merchants'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($role = $request->validated('role')) {
            $query->role($role);
        }

        // Deactivated users are hidden by default — the common case is
        // "who can use the platform", and SoftDeletes already excludes
        // them, so only the explicit filters widen or invert that.
        $status = $request->validated('status');
        if ($status === 'deactivated') {
            $query->onlyTrashed();
        } elseif ($status === null) {
            $query->withTrashed();
        }

        if ($search = $request->validated('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->paginate($request->validated('per_page', 25))->withQueryString();

        return AdminUserListResource::collection($users);
    }

    public function show(User $user): AdminUserDetailResource
    {
        $user->load(['roles', 'merchants']);

        return new AdminUserDetailResource($user, $this->historyFor($user));
    }

    public function store(CreateUserRequest $request, CreateUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $action->execute($request->payload(), $actor);

        /** @var User $user */
        $user = $result['user'];
        $user->load(['roles', 'merchants']);

        $payload = (new AdminUserDetailResource($user, (new AuditLog)->newCollection()))->resolve();

        // Dev-only, matching TeamController::store() and
        // AdminMerchantController::store(): the plaintext invite token is
        // never returned in production, and never by a read endpoint.
        if (app()->environment(['local', 'development'])) {
            $invitation = $result['invitation'];
            $payload['invite'] = [
                'token' => $invitation['token'],
                'expires_at' => $invitation['expires_at']->toISOString(),
                'url' => $invitation['invite_url'],
            ];
        }

        return response()->json($payload, JsonResponse::HTTP_CREATED);
    }

    public function updateRole(
        ChangeUserRoleRequest $request,
        User $user,
        ChangeUserRoleAction $action,
    ): AdminUserDetailResource {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $request->payload()['role'], $actor);

        $user = $user->fresh(['roles', 'merchants']);

        return new AdminUserDetailResource($user, $this->historyFor($user));
    }

    public function destroy(Request $request, User $user, DeactivateUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $actor);

        // A small 200 body rather than a bare 204, matching
        // AuthController::logout() and TeamController::destroy().
        return response()->json([
            'message' => 'User deactivated.',
            'code' => 'user_deactivated',
        ]);
    }

    public function restore(Request $request, User $user, RestoreUserAction $action): AdminUserDetailResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($user, $actor);

        $user = $user->fresh(['roles', 'merchants']);

        return new AdminUserDetailResource($user, $this->historyFor($user));
    }

    public function resendInvite(Request $request, User $user, ResendUserInviteAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $invitation = $action->execute($user, $actor);

        $payload = [
            'message' => 'Invite resent.',
            'code' => 'invite_resent',
        ];

        if (app()->environment(['local', 'development'])) {
            $payload['invite'] = [
                'token' => $invitation['token'],
                'expires_at' => $invitation['expires_at']->toISOString(),
                'url' => $invitation['invite_url'],
            ];
        }

        return response()->json($payload);
    }

    /**
     * This user's own admin-action history, drawn from audit_logs the same
     * way AdminMerchantController reads a merchant's status history.
     *
     * @return Collection<int, AuditLog>
     */
    private function historyFor(User $user): Collection
    {
        return AuditLog::query()
            ->with('actor')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}
