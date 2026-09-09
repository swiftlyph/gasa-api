<?php

namespace App\Domains\CashSessions\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Http\Resources\RegisterResource;
use App\Domains\CashSessions\Models\Register;
use App\Domains\CashSessions\Support\DefaultRegister;
use App\Domains\Merchant\Models\Merchant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /merchant/registers — listing only. No register CRUD this phase;
 * see Register's docblock.
 */
class RegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Register::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Merchant $merchant */
        $merchant = $user->merchant();

        $registers = Register::query()->oldest('id')->get();

        // Resolved once for the whole list, not per row — see
        // RegisterResource's docblock for why the default isn't
        // recomputed inside the resource itself.
        $defaultId = $registers->isNotEmpty()
            ? DefaultRegister::for($merchant->getKey())->getKey()
            : null;

        $data = $registers
            ->map(fn (Register $register) => (new RegisterResource($register, $defaultId))->resolve())
            ->values();

        // Explicit { data: [...] } envelope, matching every other
        // unpaginated list endpoint (see README § Response shapes) —
        // MenuController and KitchenQueueController do the same rather
        // than relying on JsonResource::make()'s wrapping behaviour.
        return response()->json(['data' => $data]);
    }
}
