<?php

namespace App\Domains\CashSessions\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Actions\ConfirmRemittanceAction;
use App\Domains\CashSessions\Actions\CreateRemittanceAction;
use App\Domains\CashSessions\Http\Requests\CreateRemittanceRequest;
use App\Domains\CashSessions\Http\Resources\CashRemittanceResource;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Creates and confirms remittances. One controller for both because they
 * are two small actions on the same resource, matching
 * OrderTransitionController's "complete and void together" shape rather
 * than splitting into two single-purpose classes.
 */
class CashRemittanceController extends Controller
{
    public function store(
        CreateRemittanceRequest $request,
        CashSession $cashSession,
        CreateRemittanceAction $action,
    ): JsonResponse {
        $this->authorize('create', [CashRemittance::class, $cashSession]);

        /** @var User $creator */
        $creator = $request->user();

        $remittance = $action->execute($cashSession, $request->remittancePayload(), $creator);

        return CashRemittanceResource::make($remittance)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function confirm(
        Request $request,
        CashRemittance $remittance,
        ConfirmRemittanceAction $action,
    ): CashRemittanceResource {
        $this->authorize('confirm', $remittance);

        /** @var User $confirmer */
        $confirmer = $request->user();

        return CashRemittanceResource::make($action->execute($remittance, $confirmer));
    }
}
