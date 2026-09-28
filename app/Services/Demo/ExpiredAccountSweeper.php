<?php

namespace App\Services\Demo;

use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Purge des comptes de démo expirés que personne n'a revus.
 *
 * Un visiteur présent à l'expiration est purgé dans sa propre requête
 * (demo.alive). Ce balayage rattrape les autres : le générateur l'exécute
 * toutes les 5 minutes (ADR 0006), et la commande demo:purge-expired à la
 * demande.
 *
 * Un verrou Redis garantit qu'un seul balayage tourne à la fois, quelle qu'en
 * soit l'origine. Il expire seul si le processus qui le tient meurt.
 */
class ExpiredAccountSweeper
{
    private const LOCK = 'demo:purge-expired';

    /**
     * Durée de vie du verrou : bien au-delà d'un balayage (quelques
     * millisecondes par compte, 50 comptes au plus), bien en deçà de
     * l'intervalle entre deux balayages.
     */
    private const LOCK_SECONDS = 240;

    public function __construct(private AccountPurger $purger) {}

    /**
     * @return int|null comptes purgés, ou null si un autre balayage est en cours
     */
    public function sweep(): ?int
    {
        $lock = $this->lock();

        if (! $lock->get()) {
            return null;
        }

        try {
            $purged = 0;

            User::expiredDemo()->chunkById(100, function (Collection $users) use (&$purged): void {
                foreach ($users as $user) {
                    if ($this->purger->purge($user)) {
                        $purged++;
                    }
                }
            });

            return $purged;
        } finally {
            $lock->release();
        }
    }

    public function pending(): int
    {
        return User::expiredDemo()->count();
    }

    public function lock(): Lock
    {
        return Cache::lock(self::LOCK, self::LOCK_SECONDS);
    }
}
