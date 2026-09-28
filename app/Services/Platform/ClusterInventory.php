<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformSourceUnavailableException;
use App\Services\Metrics\Sample;
use App\Services\Metrics\VictoriaMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Photographie de la plateforme : ce que gitops déclare, et ce qui tourne
 * réellement d'après kube-state-metrics.
 */
class ClusterInventory
{
    private const BYTES_PER_MIB = 1024 * 1024;

    public function __construct(private VictoriaMetrics $metrics) {}

    /**
     * @return array<string, mixed>
     *
     * @throws PlatformSourceUnavailableException
     */
    public function report(): array
    {
        $now = CarbonImmutable::now();

        // Les métriques sont regroupées par famille plutôt qu'interrogées une à
        // une : un sélecteur `__name__=~...` coûte une seule requête là où il en
        // faudrait dix. Aucun filtre sur namespace ou pod — cf. Sample::normalizeLabels.
        $series = $this->metrics->queryMany([
            'pods' => '{__name__=~"kube_pod_info|kube_pod_status_phase|kube_pod_start_time|kube_pod_status_ready|kube_pod_container_status_restarts_total"}',
            'workloads' => '{__name__=~"kube_deployment_spec_replicas|kube_deployment_status_replicas_ready|kube_deployment_status_replicas_available|kube_statefulset_replicas|kube_statefulset_status_replicas_ready"}',
            'node' => '{__name__=~"kube_node_info|kube_node_status_condition|kube_node_status_allocatable"}',
        ]);

        $pods = $this->pods($series['pods'], $now);

        return [
            'collected_at' => $now->toIso8601String(),
            'nodes' => $this->nodes($series['node']),
            'namespaces' => $this->namespaces($pods),
            'workloads' => $this->workloads($series['workloads']),
            'pods' => $pods->values()->all(),
            'gitops' => $this->declared(),
        ];
    }

    /**
     * @param  Collection<int, Sample>  $samples
     * @return Collection<int, array<string, mixed>>
     */
    private function pods(Collection $samples, CarbonImmutable $now): Collection
    {
        $byName = $samples->groupBy(fn (Sample $sample): string => $sample->name);

        $info = $this->keyByPod($byName->get('kube_pod_info', collect()));
        $startTimes = $this->keyByPod($byName->get('kube_pod_start_time', collect()));
        // kube-state-metrics publie une série PAR VALEUR POSSIBLE de la
        // condition, et une seule vaut 1. Un pod non prêt a donc bien une série
        // condition="true" — à zéro. Ne filtrer que sur l'étiquette déclarerait
        // prêt tout pod dont kube-state-metrics parle, y compris celui qui
        // redémarre en boucle. Le même piège vaut pour la phase, juste en
        // dessous.
        $ready = $this->keyByPod(
            ($byName->get('kube_pod_status_ready', collect()))
                ->filter(fn (Sample $sample): bool => $sample->label('condition') === 'true' && $sample->value > 0),
        );

        $phases = $this->keyByPod(
            ($byName->get('kube_pod_status_phase', collect()))
                ->filter(fn (Sample $sample): bool => $sample->value > 0),
        );

        $restarts = ($byName->get('kube_pod_container_status_restarts_total', collect()))
            ->groupBy(fn (Sample $sample): string => $this->podKey($sample))
            ->map(fn (Collection $containers): int => (int) round($containers->sum(
                fn (Sample $sample): float => $sample->value,
            )));

        /** @var Collection<int, array<string, mixed>> $pods */
        $pods = $startTimes
            ->keys()
            ->merge($phases->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $key) use ($info, $startTimes, $phases, $ready, $restarts, $now): array {
                [$namespace, $pod] = explode('/', $key, 2);
                $startedAt = $startTimes->get($key)?->toTimestamp();

                return [
                    'namespace' => $namespace,
                    'name' => $pod,
                    'node' => $info->get($key)?->label('node'),
                    'owner' => $this->owner($info->get($key)),
                    'phase' => $phases->get($key)?->label('phase'),
                    'ready' => $ready->has($key),
                    'started_at' => $startedAt?->toIso8601String(),
                    // Carbon 3 renvoie un flottant : sans la conversion, la
                    // durée sortirait en 779451.210049 secondes.
                    'uptime_seconds' => $startedAt === null ? null : (int) $startedAt->diffInSeconds($now, absolute: true),
                    'restarts' => $restarts->get($key, 0),
                ];
            });

        return $pods;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pods
     * @return list<array<string, mixed>>
     */
    private function namespaces(Collection $pods): array
    {
        return $pods
            ->groupBy('namespace')
            ->map(fn (Collection $namespacePods, string $namespace): array => [
                'name' => $namespace,
                'pods' => [
                    'total' => $namespacePods->count(),
                    'ready' => $namespacePods->where('ready', true)->count(),
                    'by_phase' => $namespacePods
                        ->groupBy(fn (array $pod): string => $pod['phase'] ?? 'Unknown')
                        ->map(fn (Collection $group): int => $group->count())
                        ->sortKeys()
                        ->all(),
                ],
                'restarts' => (int) $namespacePods->sum('restarts'),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Sample>  $samples
     * @return list<array<string, mixed>>
     */
    private function workloads(Collection $samples): array
    {
        $kinds = [
            'Deployment' => [
                'selector' => 'deployment',
                'desired' => 'kube_deployment_spec_replicas',
                'ready' => 'kube_deployment_status_replicas_ready',
                'available' => 'kube_deployment_status_replicas_available',
            ],
            'StatefulSet' => [
                'selector' => 'statefulset',
                'desired' => 'kube_statefulset_replicas',
                'ready' => 'kube_statefulset_status_replicas_ready',
                'available' => null,
            ],
        ];

        return collect($kinds)
            ->flatMap(function (array $metrics, string $kind) use ($samples): Collection {
                $named = fn (?string $metric): Collection => $metric === null
                    ? collect()
                    : $samples
                        ->filter(fn (Sample $sample): bool => $sample->name === $metric)
                        ->keyBy(fn (Sample $sample): string => $sample->label('namespace', '').'/'.$sample->label($metrics['selector'], ''));

                $desired = $named($metrics['desired']);
                $ready = $named($metrics['ready']);
                $available = $named($metrics['available']);

                return $desired->map(function (Sample $sample, string $key) use ($kind, $ready, $available): array {
                    [$namespace, $name] = explode('/', $key, 2);

                    return [
                        'kind' => $kind,
                        'namespace' => $namespace,
                        'name' => $name,
                        'desired_replicas' => $sample->toInt(),
                        'ready_replicas' => $ready->get($key)?->toInt() ?? 0,
                        'available_replicas' => $available->get($key)?->toInt(),
                    ];
                })->values();
            })
            ->sortBy([['namespace', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Sample>  $samples
     * @return list<array<string, mixed>>
     */
    private function nodes(Collection $samples): array
    {
        $byNode = $samples->groupBy(fn (Sample $sample): string => $sample->label('node', ''));

        return $byNode
            ->map(function (Collection $nodeSamples, string $node): array {
                $info = $nodeSamples->firstWhere(fn (Sample $sample): bool => $sample->name === 'kube_node_info');
                $allocatable = $nodeSamples
                    ->filter(fn (Sample $sample): bool => $sample->name === 'kube_node_status_allocatable')
                    ->keyBy(fn (Sample $sample): string => $sample->label('resource', ''));

                $memory = $allocatable->get('memory')?->value;

                return [
                    'name' => $node,
                    'kubelet_version' => $info?->label('kubelet_version'),
                    'container_runtime' => $info?->label('container_runtime_version'),
                    'os_image' => $info?->label('os_image'),
                    'ready' => $nodeSamples->contains(
                        fn (Sample $sample): bool => $sample->name === 'kube_node_status_condition'
                            && $sample->label('condition') === 'Ready'
                            && $sample->label('status') === 'true'
                            && $sample->value > 0,
                    ),
                    'allocatable' => [
                        'cpu_cores' => $allocatable->get('cpu')?->value,
                        'memory_mib' => $memory === null ? null : (int) round($memory / self::BYTES_PER_MIB),
                        'pods' => $allocatable->get('pods')?->toInt(),
                    ],
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * L'inventaire tel que gitops le déclare, et ce que sa somme fait du budget
     * mémoire du nœud.
     *
     * @return array<string, mixed>
     */
    private function declared(): array
    {
        /** @var Collection<int, array<string, mixed>> $components */
        $components = collect((array) config('platform.components'));
        $budget = (int) config('platform.memory_budget_mib');
        $requested = (int) $components->sum(fn (array $component): int => (int) data_get($component, 'memory.request_mib', 0));

        return [
            'memory' => [
                'requested_mib' => $requested,
                'budget_mib' => $budget,
                'headroom_mib' => $budget - $requested,
            ],
            'components' => $components
                ->sortBy([['wave', 'asc'], ['name', 'asc']])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Sample>  $samples
     * @return Collection<string, Sample>
     */
    private function keyByPod(Collection $samples): Collection
    {
        return $samples->keyBy(fn (Sample $sample): string => $this->podKey($sample));
    }

    private function podKey(Sample $sample): string
    {
        return $sample->label('namespace', '').'/'.$sample->label('pod', '');
    }

    /**
     * @return array{kind: string, name: string}|null
     */
    private function owner(?Sample $info): ?array
    {
        $kind = $info?->label('created_by_kind');
        $name = $info?->label('created_by_name');

        return $kind === null || $name === null || $kind === '<none>'
            ? null
            : ['kind' => $kind, 'name' => $name];
    }
}
