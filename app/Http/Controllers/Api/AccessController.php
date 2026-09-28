<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\DemoAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessController extends Controller
{
    /**
     * Accès temporaire, borné par sa durée, par le quota d'élévations et par
     * l'expiration du compte.
     *
     * @throws ValidationException
     */
    public function elevate(Request $request, DemoAccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'ability' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $grant = $accounts->elevate($user, $data['ability']);

        return response()->json([
            'ability' => $grant->ability,
            'expires_at' => $grant->expires_at?->toIso8601String(),
        ], 201);
    }
}
