<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ouverture de la session d'un compte de démo, commune à la connexion
 * anonyme et à la connexion OAuth.
 */
class DemoLogin
{
    public function __construct(private SessionTracker $sessions) {}

    public function open(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        // Nouvel identifiant : une session ouverte avant la connexion ne
        // devient jamais celle du compte (fixation de session).
        $request->session()->regenerate();

        $this->sessions->track($user->id, $request->session()->getId(), (int) $user->secondsRemaining());
    }
}
