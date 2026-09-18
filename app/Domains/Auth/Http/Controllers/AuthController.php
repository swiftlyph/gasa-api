<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Auth\Actions\LoginAction;
use App\Domains\Auth\Actions\LogoutAction;
use App\Domains\Auth\Http\Requests\LoginRequest;
use App\Domains\Auth\Http\Resources\UserResource;
use App\Domains\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController
{
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $validated = $request->validated();

        $result = $action->execute($validated['email'], $validated['password'], $validated['portal']);

        return response()->json([
            'token' => $result['token']->plainTextToken,
            'user' => new UserResource($result['user']->load(['roles', 'merchants', 'company'])),
        ]);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load(['roles', 'merchants', 'company']));
    }

    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        $action->execute($request->user()->currentAccessToken());

        return response()->json(['message' => 'Logged out.', 'code' => 'logged_out']);
    }
}
