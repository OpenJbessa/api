<?php

namespace App\Console\Commands;

use App\Services\Stream\EventStream;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RedisException;

/**
 * Consommateur du stream demo:events, dans le consumer group `workers`.
 *
 * C'est la charge que KEDA met à l'échelle, de 1 à 4 réplicas, sur le retard
 * du group. Chaque réplica s'annonce sous le nom d'hôte de son pod.
 *
 * Au démarrage : crée le group s'il manque, oublie les consommateurs disparus
 * depuis longtemps, puis reprend par XAUTOCLAIM les entrées qu'un pod tué en
 * plein lot a laissées en attente.
 *
 * Sur SIGTERM (ou SIGQUIT, le signal d'arrêt de l'image) : finit le lot en
 * cours, l'acquitte, et n'en prend pas d'autre.
 *
 * À chaque tour de boucle, il touche un fichier de battement de cœur
 * (demo.stream.heartbeat_path) : la sonde de vie du pod vérifie son âge, ce
 * qui distingue un consommateur bloqué d'un consommateur qui attend.
 * L'attente de XREADGROUP (BLOCK 2000) borne le délai d'arrêt à deux secondes,
 * bien en deçà du terminationGracePeriodSeconds du pod.
 */
#[Signature('demo:consume-events
    {--consumer= : Nom du consommateur (défaut : nom d\'hôte du pod)}
    {--once : Un seul cycle (reprise puis une lecture), puis arrêt}')]
#[Description('Consomme le stream demo:events dans le consumer group workers')]
class ConsumeDemoEvents extends Command
{
    private bool $running = true;

    public function handle(EventStream $stream): int
    {
        // SIGQUIT aussi : l'image hérite de STOPSIGNAL SIGQUIT (arrêt propre de
        // PHP-FPM), et c'est ce signal que containerd envoie à l'arrêt du pod.
        $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
            $this->running = false;
        });

        $consumer = (string) ($this->option('consumer') ?: gethostname());

        $stream->ensureGroup();
        $forgotten = $stream->forgetDeadConsumers();
        $claimed = $this->process($stream, $stream->claimStale($consumer));

        Log::info('demo.consumer.started', ['consumer' => $consumer, 'claimed' => $claimed, 'forgotten_consumers' => $forgotten]);

        while ($this->running) {
            $this->heartbeat();

            try {
                $entries = $stream->read($consumer);
            } catch (RedisException $exception) {
                // Un signal reçu pendant le blocage peut interrompre la lecture.
                if (! $this->shouldKeepRunning()) {
                    break;
                }

                throw $exception;
            }

            // Même si SIGTERM arrive pendant le traitement, le lot est mené à
            // son terme et acquitté.
            $this->process($stream, $entries);

            if ($this->option('once')) {
                break;
            }
        }

        Log::info('demo.consumer.stopped', ['consumer' => $consumer]);

        return self::SUCCESS;
    }

    private function heartbeat(): void
    {
        touch((string) config('demo.stream.heartbeat_path'));
    }

    /**
     * Lu à travers une méthode : la valeur change dans le gestionnaire de
     * signal, hors du flux que l'analyse statique suit.
     */
    private function shouldKeepRunning(): bool
    {
        return $this->running;
    }

    /**
     * Traitement simulé de chaque entrée, puis XACK du lot entier.
     *
     * @param  array<string, array<string, string>>  $entries
     */
    private function process(EventStream $stream, array $entries): int
    {
        $workMicroseconds = max(0, (int) config('demo.stream.work_ms')) * 1000;

        foreach ($entries as $entry) {
            if ($workMicroseconds > 0) {
                usleep($workMicroseconds);
            }
        }

        return $stream->acknowledge(array_map('strval', array_keys($entries)));
    }
}
