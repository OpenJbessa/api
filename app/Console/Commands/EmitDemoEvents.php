<?php

namespace App\Console\Commands;

use App\Services\Demo\BurstTrigger;
use App\Services\Demo\ExpiredAccountSweeper;
use App\Services\Stream\EventStream;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Générateur d'événements synthétiques de la démo KEDA.
 *
 * Tourne en continu (Deployment à un réplica). À chaque seconde, il écrit un
 * lot dans le stream : le débit de fond (--rate) tant que la clé demo:burst
 * est absente, DEMO_BURST_RATE tant qu'elle existe. La rafale n'a donc besoin
 * d'aucune file : POST /demo/burst pose la clé, ce générateur la lit, et elle
 * expire seule à la fin de la rafale.
 *
 * Il porte aussi la purge des comptes expirés abandonnés : au démarrage, puis
 * toutes les DEMO_PURGE_INTERVAL_SECONDS (ADR 0006). Une purge en échec est
 * journalisée et retentée au passage suivant ; elle n'arrête jamais l'émission.
 *
 * S'arrête proprement sur SIGTERM, SIGINT ou SIGQUIT (le signal d'arrêt de
 * l'image), à la fin du lot en cours.
 */
#[Signature('demo:emit-events
    {--rate= : Débit de fond, en événements par seconde (défaut : DEMO_BACKGROUND_RATE)}
    {--ticks=0 : Nombre de secondes à émettre avant de s\'arrêter, 0 pour tourner sans fin}')]
#[Description('Écrit des événements synthétiques dans le stream demo:events')]
class EmitDemoEvents extends Command
{
    private bool $running = true;

    public function handle(EventStream $stream, BurstTrigger $burst, ExpiredAccountSweeper $sweeper): int
    {
        // SIGQUIT aussi : l'image hérite de STOPSIGNAL SIGQUIT (arrêt propre de
        // PHP-FPM), et c'est ce signal que containerd envoie à l'arrêt du pod.
        $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
            $this->running = false;
        });

        $backgroundRate = max(0, (int) ($this->option('rate') ?? config('demo.stream.background_rate')));
        $ticks = max(0, (int) $this->option('ticks'));
        $wasBursting = false;
        $tick = 0;
        $purgeInterval = max(1, (int) config('demo.purge_interval_seconds'));
        $nextPurgeAt = 0.0;

        // Le group existe avant le premier consommateur : le retard se mesure
        // dès le premier événement.
        $stream->ensureGroup();

        Log::info('demo.emitter.started', ['background_rate' => $backgroundRate]);

        while ($this->running) {
            $startedAt = microtime(true);
            $bursting = $burst->isRunning();

            if ($bursting !== $wasBursting) {
                Log::info($bursting ? 'demo.burst.started' : 'demo.burst.ended');
                $wasBursting = $bursting;
            }

            $stream->publish($bursting ? (int) config('demo.burst.rate') : $backgroundRate, $bursting);

            if ($startedAt >= $nextPurgeAt) {
                $this->sweep($sweeper);
                $nextPurgeAt = $startedAt + $purgeInterval;
            }

            if ($ticks > 0 && ++$tick >= $ticks) {
                break;
            }

            $this->sleepUntil($startedAt + 1.0);
        }

        Log::info('demo.emitter.stopped');

        return self::SUCCESS;
    }

    /**
     * Balayage des comptes expirés. La connexion à PostgreSQL est fermée
     * ensuite : entre deux passages, cinq minutes d'inactivité la feraient
     * couper côté serveur ou par le réseau. Jamais au milieu d'une transaction
     * (celle des tests, en pratique) : la fermer l'annulerait.
     */
    private function sweep(ExpiredAccountSweeper $sweeper): void
    {
        try {
            $purged = $sweeper->sweep();

            Log::info('demo.purge.swept', ['purged' => $purged, 'skipped' => $purged === null]);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            if (DB::transactionLevel() === 0) {
                DB::disconnect();
            }
        }
    }

    /**
     * Attente par petites tranches : un SIGTERM est pris en compte en moins
     * de 100 ms, pas à la fin de la seconde.
     */
    private function sleepUntil(float $deadline): void
    {
        while ($this->running && ($remaining = $deadline - microtime(true)) > 0) {
            usleep((int) (min($remaining, 0.1) * 1_000_000));
        }
    }
}
