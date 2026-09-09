<?php

namespace App\Domains\CashSessions\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Actions\RecordCashMovementAction;
use App\Domains\CashSessions\Http\Requests\RecordCashMovementRequest;
use App\Domains\CashSessions\Http\Resources\CashMovementResource;
use App\Domains\CashSessions\Models\CashMovement;
use App\Domains\CashSessions\Models\CashSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * POST /merchant/cash-sessions/{cashSession}/movements — record cash in
 * or out. Its own controller rather than a CashSessionController method,
 * matching CheckoutController's reasoning for being separate from
 * OrderController: a movement is created against an explicit session, not
 * addressed by its own id, so it belongs beside the session it modifies
 * in the routes file rather than folded into the session's own CRUD.
 */
class CashMovementController extends Controller
{
    public function store(
        RecordCashMovementRequest $request,
        CashSession $cashSession,
        RecordCashMovementAction $action,
    ): JsonResponse {
        $this->authorize('create', [CashMovement::class, $cashSession]);

        /** @var User $recordedBy */
        $recordedBy = $request->user();

        $movement = $action->execute($cashSession, $request->movementPayload(), $recordedBy);

        return CashMovementResource::make($movement)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
