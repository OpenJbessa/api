<?php

namespace App\Http\Resources;

use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil du visiteur, tel que le front l'attend : à plat, sans enveloppe
 * `data`, et sans aucun identifiant interne.
 *
 * `server_time` permet au front de corriger la dérive de l'horloge du
 * navigateur dans son compte à rebours.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'is_demo' => $this->is_demo,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'seconds_remaining' => $this->secondsRemaining(),
            'server_time' => now()->toIso8601String(),
            'providers' => $this->socialAccounts()->pluck('provider')->values()->all(),
            'grants' => $this->activeGrants()
                ->orderBy('ability')
                ->get()
                ->map(fn (AccessGrant $grant): array => [
                    'ability' => $grant->ability,
                    'granted_via' => $grant->granted_via,
                    'expires_at' => $grant->expires_at?->toIso8601String(),
                ])
                ->all(),
            'elevatable' => (object) config('demo.elevatable'),
            'elevations_remaining' => $this->elevationsRemaining(),
        ];
    }
}
