<?php

namespace App\Domains\Merchant\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Http\Requests\IndexMerchantAuditLogsRequest;
use App\Domains\Merchant\Http\Resources\MerchantAuditLogResource;
use App\Domains\Merchant\Models\MerchantAuditLog;
use App\Domains\Merchant\Policies\MerchantAuditLogPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /merchant/audit-log — a merchant's own audit trail. Owner/Manager
 * only (audit_log.view, see RolePresets — Staff never holds it).
 *
 * Uses the container-resolved policy directly, matching TeamController's
 * precedent, rather than $this->authorize(): there is no {merchantAuditLog}
 * model to bind here (list-only, no single-resource route), so
 * $this->authorize('viewAny', MerchantAuditLog::class) would work too, but
 * calling the policy directly keeps this consistent with the other
 * permission-only (no-model) abilities in this domain.
 */
class MerchantAuditLogController extends Controller
{
    public function index(IndexMerchantAuditLogsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        app(MerchantAuditLogPolicy::class)->viewAny($user);

        $query = MerchantAuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        // BelongsToMerchant's global scope already restricts this to the
        // caller's own merchant — no merchant_id filter is exposed here.

        if ($actorId = $request->validated('actor_user_id')) {
            $query->where('actor_user_id', $actorId);
        }

        if ($action = $request->validated('action')) {
            $query->where('action', $action);
        }

        if ($subjectType = $request->validated('subject_type')) {
            $query->where('subject_type', $subjectType);
        }

        if ($subjectId = $request->validated('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($range = $request->queryRange()) {
            [$from, $toExclusive] = $range;
            $query->where('created_at', '>=', $from)
                ->where('created_at', '<', $toExclusive);
        }

        $logs = $query->paginate($request->validated('per_page', 25))->withQueryString();

        return MerchantAuditLogResource::collection($logs);
    }
}
