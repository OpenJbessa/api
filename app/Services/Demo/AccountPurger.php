<?php

namespace App\Services\Demo;

use App\Exceptions\PermanentAccountException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

/**
 * Supprime un compte de démo et toutes ses traces.
 *
 * Idempotent : un second appel, un balayage en retard ou une purge sur une
 * instance périmée du modèle ne suppriment rien de plus et ne lèvent rien.
 *
 * Traces couvertes :
 *  - la ligne users, et par cascade social_accounts et access_grants ;
 *  - les jetons Sanctum (relation polymorphe : pas de cascade possible) ;
 *  - les jetons de réinitialisation de mot de passe ;
 *  - les sessions Redis, retrouvées par l'index SessionTracker ;
 *  - les clés de cache liées au compte (compteur de débit des élévations).
 */
class AccountPurger
{
    public function __construct(private SessionTracker $sessions) {}

    /**
     * @return bool vrai si le compte existait encore et vient d'être supprimé
     *
     * @throws PermanentAccountException
     */
    public function purge(User $user): bool
    {
        if (! $user->is_demo) {
            throw new PermanentAccountException;
        }

        $sessionIds = $this->sessions->pull($user->id);

        $deleted = DB::transaction(function () use ($user): bool {
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // Requête directe et non $user->delete() : sur une instance déjà
            // supprimée, le modèle croirait encore exister.
            return User::query()->whereKey($user->id)->delete() > 0;
        });

        $handler = Session::getHandler();

        foreach ($sessionIds as $sessionId) {
            $handler->destroy($sessionId);
        }

        RateLimiter::clear(self::elevationLimiterKey($user->id));

        if ($deleted) {
            // Aucun identifiant : on garde la mesure, pas la personne.
            Log::info('demo.account.purged', [
                'lifetime_seconds' => $user->created_at === null ? null : (int) $user->created_at->diffInSeconds(now(), absolute: true),
                'sessions' => count($sessionIds),
            ]);
        }

        return $deleted;
    }

    /**
     * Clé de cache du limiteur `elevate` (AppServiceProvider) pour un compte.
     *
     * ThrottleRequests hache le nom du limiteur concaténé à la clé de la
     * limite ; la recomposer ici est le seul moyen d'effacer ce compteur.
     */
    public static function elevationLimiterKey(int $userId): string
    {
        return md5('elevate'.'elevate:'.$userId);
    }
}
