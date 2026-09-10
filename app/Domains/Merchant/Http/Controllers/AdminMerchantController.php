<?php

namespace App\Domains\Merchant\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\ChangeMerchantStatusAction;
use App\Domains\Merchant\Actions\ProvisionMerchantAction;
use App\Domains\Merchant\Actions\ResendMerchantInviteAction;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Http\Requests\IndexAdminMerchantsRequest;
use App\Domains\Merchant\Http\Requests\ProvisionMerchantRequest;
use App\Domains\Merchant\Http\Requests\UpdateMerchantStatusRequest;
use App\Domains\Merchant\Http\Resources\AdminMerchantDetailResource;
use App\Domains\Merchant\Http\Resources\AdminMerchantListResource;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform-admin merchant provisioning and management — every route here
 * sits behind the admin.api group (auth:sanctum + role:platform_admin +
 * AllowsAdminContext, see bootstrap/app.php), which is what makes the
 * cross-merchant reads below safe: BelongsToMerchant's global scope is
 * bypassed for the whole request, not by anything this controller does
 * itself. No policy layer sits in front of these actions — every
 * platform_admin may act on every merchant, so the route group IS the
 * authorization boundary (see README § Platform admin).
 */
class AdminMerchantController extends Controller
{
    /**
     * A small, FIXED number of queries regardless of how many merchants
     * match: one count query (pagination), one page query with `owner`
     * eager-loaded, one aggregate for `users_count` and one for
     * `registers_exists` — both folded into the same query via
     * withCount()/withExists() rather than looping. See
     * tests/Feature/Admin/AdminMerchantListTest.php's bounded-query-count
     * case.
     */
    public function index(IndexAdminMerchantsRequest $request): AnonymousResourceCollection
    {
        $query = Merchant::query()
            ->with('owner')
            ->withCount('users')
            ->withExists('registers')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->validated('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhereHas('owner', function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        $merchants = $query->paginate($request->validated('per_page', 25))->withQueryString();

        return AdminMerchantListResource::collection($merchants);
    }

    public function show(Merchant $merchant): AdminMerchantDetailResource
    {
        $merchant->load(['owner', 'users', 'registers']);

        $statusHistory = AuditLog::query()
            ->where('subject_type', $merchant->getMorphClass())
            ->where('subject_id', $merchant->getKey())
            ->where('action', 'merchant.status_changed')
            ->orderByDesc('created_at')
            ->get();

        return new AdminMerchantDetailResource($merchant, $statusHistory);
    }

    public function store(ProvisionMerchantRequest $request, ProvisionMerchantAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $result = $action->execute($request->payload(), $actor);

        /** @var Merchant $merchant */
        $merchant = $result['merchant'];
        $merchant->load(['owner', 'users', 'registers']);

        // A brand-new merchant has no status history yet. newCollection()
        // builds an empty Eloquent\Collection with no query at all —
        // AdminMerchantDetailResource's constructor is typed against that,
        // not the base Support\Collection a plain collect() would return.
        $payload = (new AdminMerchantDetailResource($merchant, (new AuditLog)->newCollection()))->resolve();

        // Dev-only, matching TeamController::store()'s exact convention:
        // the plaintext invite token/link is never returned in
        // production. Never sent by real email this phase.
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

    public function updateStatus(
        UpdateMerchantStatusRequest $request,
        Merchant $merchant,
        ChangeMerchantStatusAction $action,
    ): AdminMerchantDetailResource {
        /** @var User $actor */
        $actor = $request->user();

        $payload = $request->payload();

        $action->execute(
            $merchant,
            MerchantStatus::from($payload['status']),
            $payload['reason'] ?? null,
            $actor,
        );

        $merchant = $merchant->fresh(['owner', 'users', 'registers']);

        $statusHistory = AuditLog::query()
            ->where('subject_type', $merchant->getMorphClass())
            ->where('subject_id', $merchant->getKey())
            ->where('action', 'merchant.status_changed')
            ->orderByDesc('created_at')
            ->get();

        return new AdminMerchantDetailResource($merchant, $statusHistory);
    }

    public function resendInvite(Request $request, Merchant $merchant, ResendMerchantInviteAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $invitation = $action->execute($merchant, $actor);

        $payload = [
            'message' => 'Invite resent.',
            'code' => 'invite_resent',
        ];

        // Dev-only, same convention as store() above.
        if (app()->environment(['local', 'development'])) {
            $payload['invite'] = [
                'token' => $invitation['token'],
                'expires_at' => $invitation['expires_at']->toISOString(),
                'url' => $invitation['invite_url'],
            ];
        }

        return response()->json($payload);
    }
}
