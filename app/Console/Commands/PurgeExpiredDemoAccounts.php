<?php

namespace App\Console\Commands;

use App\Services\Demo\ExpiredAccountSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Supprime les comptes de démo expirés et toutes leurs traces, à la demande.
 *
 * En production, le même balayage tourne toutes les 5 minutes dans le
 * générateur (ADR 0006) : cette commande ne sert qu'à une purge manuelle.
 * Elle partage son verrou avec le générateur : jamais deux purges à la fois.
 */
#[Signature('demo:purge-expired {--dry-run : Compte les comptes à purger sans rien supprimer}')]
#[Description('Supprime les comptes de démonstration expirés et leurs traces')]
class PurgeExpiredDemoAccounts extends Command
{
    public function handle(ExpiredAccountSweeper $sweeper): int
    {
        if ($this->option('dry-run')) {
            $this->info("À purger : {$sweeper->pending()}");

            return self::SUCCESS;
        }

        $purged = $sweeper->sweep();

        if ($purged === null) {
            $this->warn('Une purge est déjà en cours : rien à faire.');

            return self::SUCCESS;
        }

        $this->info("Purgés : {$purged}");

        return self::SUCCESS;
    }
}
