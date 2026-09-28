<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `grant`. Exige un accès actif : ->middleware('grant:load:burst').
 *
 * Les comptes permanents (propriétaire) ne sont pas soumis aux accès
 * temporaires.
 */
class RequireGrant
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_demo) {
            return $next($request);
        }

        $grant = $user instanceof User ? $user->activeGrant($ability) : null;

        if ($grant === null) {
            return response()->json([
                'code' => 'grant_required',
                'message' => 'Cette action demande un accès temporaire.',
                'ability' => $ability,
                // Vrai si l'accès se demande par POST /access/elevate. Le nom
                // `elevatable` est réservé à l'objet { ability: minutes } du profil.
                'requestable' => array_key_exists($ability, (array) config('demo.elevatable')),
            ], 403);
        }

        $response = $next($request);

        if ($grant->expires_at !== null) {
            $response->headers->set('X-Grant-Expires-At', $grant->expires_at->toIso8601String());
        }

        return $response;
    }
}
