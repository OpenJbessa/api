<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\DemoCapacityReachedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoLoginController extends Controller
{
    /**
     * Crée un compte anonyme et ouvre sa session.
     *
     * La session n'existe que pour un appel du front : Sanctum ne démarre la
     * session que pour les origines de SANCTUM_STATEFUL_DOMAINS.
     *
     * @throws DemoCapacityReachedException
     */
    public function __invoke(Request $request, DemoAccountService $accounts, DemoLogin $login): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'origin_not_allowed',
                'message' => 'La démonstration s\'utilise depuis le site jbessa.tech.',
            ], 400);
        }

        $user = $accounts->create();
        $login->open($request, $user);

        return (new ProfileResource($user))->response()->setStatusCode(201);
    }
}
