<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformSourceUnavailableException;
use App\Services\Metrics\Sample;
use App\Services\Metrics\VictoriaMetrics;
use App\Services\Stream\EventStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * État de l'autoscaling du worker : ce que KEDA est censé faire, ce que le
 * stream contient, et ce que le Deployment affiche.
 *
 * Les deux sources se complètent sans se remplacer. Redis dit la vérité sur le
 * stream ; KEDA dit ce qu'il croit y lire. Quand les deux divergent, c'est que
 * le préfixe de clé a bougé — et le worker reste alors à un réplica sans
 * qu'aucune erreur ne remonte nulle part.
 */
class WorkerScaling
{
    public function __construct(
        private VictoriaMetrics $metrics,
        private EventStream $stream,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $scaling = config('platform.scaling');
        $unavailable = [];

        $stream = $this->streamState($unavailable);
        $deployment = $this->deploymentState($scaling, $unavailable);

        $lag = $stream['lag'];
        $projected = $this->projectedReplicas($lag, $scaling);

        return [
            'collected_at' => CarbonImmutable::now()->toIso8601String(),
            'unavailable_sources' => array_values(array_unique($unavailable)),
            'worker' => [
                'autoscaler' => 'keda',
                'namespace' => $scaling['namespace'],
                'deployment' => $scaling['deployment'],
                'scaled_object' => $scaling['scaled_object'],
                'min_replicas' => $scaling['min_replicas'],
                'max_replicas' => $scaling['max_replicas'],
                'polling_interval_seconds' => $scaling['polling_interval_seconds'],
                'cooldown_seconds' => $scaling['cooldown_seconds'],
                'scale_down_stabilization_seconds' => $scaling['scale_down_stabilization_seconds'],
                'replicas' => $deployment['replicas'],
                'at_ceiling' => $deployment['replicas']['desired'] !== null
                    && $deployment['replicas']['desired'] >= $scaling['max_replicas'],
                'projected_replicas' => $projected,
            ],
            'stream' => [
                ...$stream,
                'lag_count' => $scaling['stream']['lag_count'],
                // La règle KEDA demande plus que le réplica permanent.
                'scaling_up' => $projected !== null && $projected > $scaling['min_replicas'],
            ],
            'keda' => [
                'observed_lag' => $deployment['keda_metric'],
                'watched_stream' => $scaling['stream']['keda_stream_name'],
                ...$this->keyAlignment($scaling),
            ],
        ];
    }

    /**
     * État réel du stream et de son consumer group, lu dans Redis.
     *
     * `active_consumers` compte les workers vivants d'après XINFO CONSUMERS :
     * c'est, vu de Redis, le nombre de réplicas qui consomment réellement.
     *
     * @param  list<string>  $unavailable
     * @return array{key: string, group: string, length: int|null, lag: int|null, pending: int|null, active_consumers: int|null}
     */
    private function streamState(array &$unavailable): array
    {
        $identity = ['key' => $this->effectiveKey($this->stream->key()), 'group' => $this->stream->group()];

        try {
            $group = $this->stream->groupState();

            return [
                ...$identity,
                'length' => $group['length'],
                'lag' => $group['lag'],
                'pending' => $group['pending'],
                'active_consumers' => $this->stream->activeConsumers(),
            ];
        } catch (Throwable $exception) {
            report($exception);
            $unavailable[] = 'redis';

            return [...$identity, 'length' => null, 'lag' => null, 'pending' => null, 'active_consumers' => null];
        }
    }

    /**
     * Réplicas du Deployment et retard vu par KEDA.
     *
     * `keda_scaler_metrics_value` est publiée par le scaler lui-même : c'est la
     * valeur sur laquelle il décide, et non une seconde lecture de Redis.
     *
     * @param  array<string, mixed>  $scaling
     * @param  list<string>  $unavailable
     * @return array{replicas: array<string, int|null>, keda_metric: int|null}
     */
    private function deploymentState(array $scaling, array &$unavailable): array
    {
        try {
            $series = $this->metrics->queryMany([
                'replicas' => sprintf(
                    '{__name__=~"kube_deployment_spec_replicas|kube_deployment_status_replicas_ready|kube_deployment_status_replicas_available",deployment="%s"}',
                    $scaling['deployment'],
                ),
                'keda' => sprintf('keda_scaler_metrics_value{scaledObject="%s"}', $scaling['scaled_object']),
            ]);
        } catch (PlatformSourceUnavailableException $exception) {
            report($exception);
            $unavailable[] = 'victoriametrics';

            return [
                'replicas' => ['desired' => null, 'ready' => null, 'available' => null],
                'keda_metric' => null,
            ];
        }

        $replicas = $series['replicas']->filter(
            fn (Sample $sample): bool => $sample->label('namespace') === $scaling['namespace'],
        );

        return [
            'replicas' => [
                'desired' => $this->firstValue($replicas, 'kube_deployment_spec_replicas'),
                'ready' => $this->firstValue($replicas, 'kube_deployment_status_replicas_ready'),
                'available' => $this->firstValue($replicas, 'kube_deployment_status_replicas_available'),
            ],
            // Un scaler peut publier plusieurs séries (une par métrique) ; la
            // plus haute est celle qui commande la montée en charge.
            'keda_metric' => $series['keda']->max(fn (Sample $sample): int => $sample->toInt()),
        ];
    }

    /**
     * Le nombre de réplicas que la règle KEDA donnerait pour le retard observé.
     *
     * Reconstruction de la formule, pas une valeur lue quelque part : l'HPA vise
     * un réplica par tranche de `lagCount`, borné par le minimum et le maximum.
     *
     * @param  array<string, mixed>  $scaling
     */
    private function projectedReplicas(?int $lag, array $scaling): ?int
    {
        if ($lag === null) {
            return null;
        }

        return min(
            $scaling['max_replicas'],
            max($scaling['min_replicas'], (int) ceil($lag / $scaling['stream']['lag_count'])),
        );
    }

    /**
     * Le ScaledObject interroge un stream écrit en dur. L'application, elle,
     * préfixe ses clés d'après `database.redis.options.prefix`. Si les deux ne
     * coïncident pas, KEDA lit un stream inexistant, ne voit aucun retard, et
     * le worker reste à un réplica — sans erreur, sans alerte, sans trace.
     *
     * @param  array<string, mixed>  $scaling
     * @return array{key_matches: bool, redis_prefix: string}
     */
    private function keyAlignment(array $scaling): array
    {
        return [
            'key_matches' => $this->effectiveKey($this->stream->key()) === $scaling['stream']['keda_stream_name'],
            'redis_prefix' => (string) config('database.redis.options.prefix', ''),
        ];
    }

    private function effectiveKey(string $key): string
    {
        return (string) config('database.redis.options.prefix', '').$key;
    }

    /**
     * @param  Collection<int, Sample>  $samples
     */
    private function firstValue(Collection $samples, string $metric): ?int
    {
        return $samples->firstWhere(fn (Sample $sample): bool => $sample->name === $metric)?->toInt();
    }
}
