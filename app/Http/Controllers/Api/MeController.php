<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PermanentAccountException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\Demo\AccountPurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class MeController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user());
    }

    /**
     * Suppression immédiate, à la demande du visiteur : le compte et toutes
     * ses traces partent maintenant, sans attendre l'expiration.
     *
     * @throws PermanentAccountException
     */
    public function destroy(Request $request, AccountPurger $purger): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->is_demo) {
            return response()->json([
                'code' => 'permanent_account',
                'message' => 'Ce compte n\'est pas un compte de démonstration : il ne se supprime pas ici.',
            ], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $purger->purge($user);

        return response()->noContent();
    }
}
