<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Http\Requests\AcceptInviteRequest;
use App\Domains\Auth\Http\Resources\UserResource;
use App\Domains\Merchant\Actions\AcceptInviteAction;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/accept-invite — public, unauthenticated. Redeems a team
 * invitation token, sets the invited user's real password, and signs
 * them in exactly like AuthController::login() does.
 *
 * A single-action controller of its own rather than a new method on
 * AuthController, matching how OrderTransitionController is split out
 * from OrderController for a related-but-distinct concern.
 *
 * The Action lives in the Merchant domain (AcceptInviteAction reads
 * TeamInvitation, a Merchant-domain model) even though this controller
 * lives in Auth — the same cross-domain shape LoginAction/AuthController
 * already have with the Merchant models they touch via User::merchant().
 */
class AcceptInviteController
{
    public function __invoke(AcceptInviteRequest $request, AcceptInviteAction $action): JsonResponse
    {
        $payload = $request->payload();

        $result = $action->execute($payload['token'], $payload['password']);

        return response()->json([
            'token' => $result['token']->plainTextToken,
            'user' => new UserResource($result['user']->load(['roles', 'merchants', 'company'])),
        ]);
    }
}
