<?php

namespace App\Domains\CashSessions\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Actions\CloseCashSessionAction;
use App\Domains\CashSessions\Actions\OpenCashSessionAction;
use App\Domains\CashSessions\Http\Requests\CloseCashSessionRequest;
use App\Domains\CashSessions\Http\Requests\IndexCashSessionsRequest;
use App\Domains\CashSessions\Http\Requests\OpenCashSessionRequest;
use App\Domains\CashSessions\Http\Requests\ShowCurrentCashSessionRequest;
use App\Domains\CashSessions\Http\Resources\CashSessionResource;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\CashSessions\Support\DefaultRegister;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Support\MerchantDay;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Open, list, read and close cash sessions. Movements and remittances have
 * their own controllers (CashMovementController, CashRemittanceController)
 * since they are always created against an explicit session rather than
 * addressed on their own — matching how OrderTransitionController is
 * separate from OrderController for the same reason.
 */
class CashSessionController extends Controller
{
    public function open(OpenCashSessionRequest $request, OpenCashSessionAction $action): JsonResponse
    {
        $this->authorize('create', CashSession::class);

        /** @var User $opener */
        $opener = $request->user();

        $cashSession = $action->execute($request->openPayload(), $opener);

        return CashSessionResource::make($cashSession)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, CashSession>>
     */
    public function index(IndexCashSessionsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CashSession::class);

        $query = CashSession::query()
            // Newest-opened first, tiebroken on id — matching
            // OrderController's reasoning: an unstable sort can show the
            // same row on two pages.
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($registerId = $request->validated('register_id')) {
            $query->where('register_id', $registerId);
        }

        // Day boundaries via MerchantDay, not inlined here — the same
        // reasoning as OrderController's ?date= filter: this endpoint and
        // the orders list must agree about where midnight is. forQuery()
        // converts the merchant-local boundary to UTC before it is bound
        // into SQL — opened_at, like created_at, is naive-UTC storage; see
        // MerchantDay's docblock.
        if ($from = $request->validated('from')) {
            $query->where('opened_at', '>=', MerchantDay::forQuery(MerchantDay::startOf($from)));
        }

        if ($to = $request->validated('to')) {
            $query->where('opened_at', '<', MerchantDay::forQuery(MerchantDay::startOf($to)->addDay()));
        }

        $sessions = $query->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return CashSessionResource::collection($sessions);
    }

    /**
     * The open session for a register (or the merchant's default),
     * null-safe when nothing is open — a shop that hasn't started its
     * shift yet gets `{ "data": null }`, not a 404, since "no open
     * session" is an entirely ordinary answer here rather than a missing
     * resource.
     */
    public function current(ShowCurrentCashSessionRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CashSession::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $registerId = $request->validated('register_id');

        $register = $registerId !== null
            ? Register::query()->findOrFail($registerId)
            : DefaultRegister::for($merchant->getKey());

        $cashSession = $register->cashSessions()
            ->where('status', 'open')
            ->with(['movements', 'remittances'])
            ->first();

        if ($cashSession === null) {
            return response()->json(['data' => null]);
        }

        $this->authorize('view', $cashSession);

        // Wrapped in { data: ... } explicitly rather than
        // CashSessionResource::make(...)->response(): withoutWrapping()
        // flattens a single resource to a bare object with no `data` key,
        // which would make this endpoint's two branches (nothing open vs.
        // something open) return incompatible shapes — a client checking
        // `response.data` would see the session directly in one case and
        // its fields spread at the top level in the other.
        return response()->json(['data' => CashSessionResource::make($cashSession)]);
    }

    public function show(CashSession $cashSession): CashSessionResource
    {
        $this->authorize('view', $cashSession);

        return CashSessionResource::make($cashSession->load(['movements', 'remittances']));
    }

    public function close(
        CloseCashSessionRequest $request,
        CashSession $cashSession,
        CloseCashSessionAction $action,
    ): CashSessionResource {
        $this->authorize('close', $cashSession);

        /** @var User $closer */
        $closer = $request->user();

        $closed = $action->execute($cashSession, $request->closePayload(), $closer);

        return CashSessionResource::make($closed->load(['movements', 'remittances']));
    }
}
