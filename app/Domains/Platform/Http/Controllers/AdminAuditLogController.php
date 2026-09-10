<?php

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Requests\IndexAuditLogsRequest;
use App\Domains\Platform\Http\Resources\AuditLogResource;
use App\Domains\Platform\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /admin/audit-logs — the only way to read audit_logs at all (see
 * that model's docblock: it carries no tenant scope of its own, so this
 * route group, behind admin.api, is its entire access control).
 */
class AdminAuditLogController extends Controller
{
    public function index(IndexAuditLogsRequest $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

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

        return AuditLogResource::collection($logs);
    }
}
