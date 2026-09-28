<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Demo\AccountPurger;
use App\Services\Demo\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `demo.alive`. Refuse toute requête d'un compte de démo expiré.
 *
 * Le refus est immédiat, à la seconde de l'expiration, et la purge aussi : le
 * front appelle /me quand son compte à rebours atteint zéro, si bien que
 * l'écran « Session terminée » décrit une suppression déjà faite. Le balayage
 * du générateur (toutes les 5 minutes, ADR 0006) ne rattrape que les
 * visiteurs partis avant.
 *
 * Deux requêtes simultanées peuvent purger le même compte : la purge est
 * idempotente, la seconde ne supprime rien et ne lève rien.
 *
 * Pour un compte vivant, la session courante est ajoutée à l'index de
 * SessionTracker, pour que la purge puisse la détruire.
 */
class EnsureAccountNotExpired
{
    public function __construct(
        private SessionTracker $sessions,
        private AccountPurger $purger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isExpired()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $this->purger->purge($user);

            return response()->json([
                'code' => 'demo_expired',
                'message' => 'Votre session de démonstration est terminée. Le compte et ses données ont été supprimés.',
            ], 401);
        }

        if ($user->is_demo && $request->hasSession()) {
            $this->sessions->track($user->id, $request->session()->getId(), (int) $user->secondsRemaining());
        }

        $response = $next($request);

        if ($user->expires_at !== null) {
            $response->headers->set('X-Account-Expires-At', $user->expires_at->toIso8601String());
        }

        return $response;
    }
}
