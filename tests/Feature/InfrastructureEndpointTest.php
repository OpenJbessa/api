<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('platform.metrics.url', 'http://vmsingle.test:8428');
});

/**
 * Une série instantanée telle que l'API de Prometheus la renvoie.
 *
 * @param  array<string, string>  $labels
 * @return array{metric: array<string, string>, value: array{0: int, 1: string}}
 */
function series(string $name, array $labels, float $value): array
{
    return [
        'metric' => ['__name__' => $name, ...$labels],
        'value' => [1_790_164_800, (string) $value],
    ];
}

/**
 * Étiquettes d'un pod telles que vmagent les produit réellement : `namespace` et
 * `pod` désignent kube-state-metrics — la CIBLE de la collecte — et le pod
 * décrit se retrouve sous `exported_*`.
 *
 * @return array<string, string>
 */
function scrapedPodLabels(string $namespace, string $pod): array
{
    return [
        'namespace' => 'observability',
        'pod' => 'kube-state-metrics-7c9f',
        'exported_namespace' => $namespace,
        'exported_pod' => $pod,
    ];
}

/**
 * @param  array<string, list<array<string, mixed>>>  $resultsByMetricFamily  indexé par un fragment
 *                                                                            de la requête PromQL
 */
function fakeVictoriaMetrics(array $resultsByMetricFamily): void
{
    Http::preventStrayRequests();

    Http::fake(['vmsingle.test:8428/api/v1/query*' => function (Request $request) use ($resultsByMetricFamily) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $query = (string) ($parameters['query'] ?? '');

        foreach ($resultsByMetricFamily as $fragment => $results) {
            if (str_contains($query, $fragment)) {
                return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $results]]);
            }
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    }]);
}

it('reports the node, its namespaces, its workloads and every pod with its uptime', function (): void {
    $this->travelTo('2026-09-23T12:00:00Z');

    // 2026-09-23T10:00:00Z, soit deux heures avant l'instant gelé.
    $startedAt = 1_790_157_600;

    fakeVictoriaMetrics([
        'kube_pod_info' => [
            series('kube_pod_info', [...scrapedPodLabels('apps', 'api-5f8c'), 'node' => 'vps-1', 'created_by_kind' => 'ReplicaSet', 'created_by_name' => 'api-5f8c'], 1),
            series('kube_pod_start_time', scrapedPodLabels('apps', 'api-5f8c'), $startedAt),
            series('kube_pod_status_phase', [...scrapedPodLabels('apps', 'api-5f8c'), 'phase' => 'Running'], 1),
            series('kube_pod_status_phase', [...scrapedPodLabels('apps', 'api-5f8c'), 'phase' => 'Pending'], 0),
            series('kube_pod_status_ready', [...scrapedPodLabels('apps', 'api-5f8c'), 'condition' => 'true'], 1),
            series('kube_pod_container_status_restarts_total', [...scrapedPodLabels('apps', 'api-5f8c'), 'container' => 'api'], 2),
        ],
        'kube_deployment_spec_replicas' => [
            series('kube_deployment_spec_replicas', ['namespace' => 'apps', 'deployment' => 'api'], 1),
            series('kube_deployment_status_replicas_ready', ['namespace' => 'apps', 'deployment' => 'api'], 1),
            series('kube_deployment_status_replicas_available', ['namespace' => 'apps', 'deployment' => 'api'], 1),
            series('kube_statefulset_replicas', ['namespace' => 'data', 'statefulset' => 'redis'], 1),
            series('kube_statefulset_status_replicas_ready', ['namespace' => 'data', 'statefulset' => 'redis'], 1),
        ],
        'kube_node_info' => [
            series('kube_node_info', ['node' => 'vps-1', 'kubelet_version' => 'v1.31.5+k3s1', 'os_image' => 'Debian GNU/Linux 12', 'container_runtime_version' => 'containerd://2.0.0'], 1),
            series('kube_node_status_condition', ['node' => 'vps-1', 'condition' => 'Ready', 'status' => 'true'], 1),
            series('kube_node_status_allocatable', ['node' => 'vps-1', 'resource' => 'memory', 'unit' => 'byte'], 8_589_934_592),
            series('kube_node_status_allocatable', ['node' => 'vps-1', 'resource' => 'cpu', 'unit' => 'core'], 4),
        ],
    ]);

    $this->getJson('/infrastructure')
        ->assertOk()
        ->assertJsonPath('data.nodes.0.name', 'vps-1')
        ->assertJsonPath('data.nodes.0.kubelet_version', 'v1.31.5+k3s1')
        ->assertJsonPath('data.nodes.0.ready', true)
        ->assertJsonPath('data.nodes.0.allocatable.memory_mib', 8192)
        ->assertJsonPath('data.namespaces.0.name', 'apps')
        ->assertJsonPath('data.namespaces.0.pods.total', 1)
        ->assertJsonPath('data.namespaces.0.pods.ready', 1)
        ->assertJsonPath('data.namespaces.0.pods.by_phase.Running', 1)
        ->assertJsonPath('data.namespaces.0.restarts', 2)
        ->assertJsonPath('data.workloads.0', [
            'kind' => 'Deployment',
            'namespace' => 'apps',
            'name' => 'api',
            'desired_replicas' => 1,
            'ready_replicas' => 1,
            'available_replicas' => 1,
        ])
        ->assertJsonPath('data.workloads.1.kind', 'StatefulSet')
        ->assertJsonPath('data.workloads.1.name', 'redis')
        ->assertJsonPath('data.pods.0.name', 'api-5f8c')
        ->assertJsonPath('data.pods.0.phase', 'Running')
        ->assertJsonPath('data.pods.0.ready', true)
        ->assertJsonPath('data.pods.0.restarts', 2)
        ->assertJsonPath('data.pods.0.uptime_seconds', 7200)
        ->assertJsonPath('data.pods.0.owner.name', 'api-5f8c');
});

it('does not call a pod ready when its readiness series sits at zero', function (): void {
    // kube-state-metrics émet une série par valeur de condition. Celle marquée
    // condition="true" existe TOUJOURS ; c'est sa valeur qui tranche. Un pod en
    // CrashLoopBackOff ressortait prêt tant que seule l'étiquette était lue.
    fakeVictoriaMetrics([
        'kube_pod_info' => [
            series('kube_pod_start_time', scrapedPodLabels('kyverno', 'kyverno-admission-1a2b'), 1_790_157_600),
            series('kube_pod_status_phase', [...scrapedPodLabels('kyverno', 'kyverno-admission-1a2b'), 'phase' => 'Running'], 1),
            series('kube_pod_status_ready', [...scrapedPodLabels('kyverno', 'kyverno-admission-1a2b'), 'condition' => 'true'], 0),
            series('kube_pod_status_ready', [...scrapedPodLabels('kyverno', 'kyverno-admission-1a2b'), 'condition' => 'false'], 1),
        ],
    ]);

    $this->getJson('/infrastructure')
        ->assertOk()
        ->assertJsonPath('data.pods.0.ready', false)
        ->assertJsonPath('data.namespaces.0.pods.ready', 0)
        ->assertJsonPath('data.namespaces.0.pods.total', 1);
});

it('reads the pod that kube-state-metrics describes, not the one it was scraped from', function (): void {
    fakeVictoriaMetrics([
        'kube_pod_info' => [
            series('kube_pod_start_time', scrapedPodLabels('data', 'postgres-1'), 1_790_157_600),
            series('kube_pod_status_phase', [...scrapedPodLabels('data', 'postgres-1'), 'phase' => 'Running'], 1),
        ],
    ]);

    // Sans la remise à plat des étiquettes `exported_*`, ce pod serait rangé
    // dans le namespace `observability` sous le nom du collecteur.
    $this->getJson('/infrastructure')
        ->assertOk()
        ->assertJsonPath('data.pods.0.namespace', 'data')
        ->assertJsonPath('data.pods.0.name', 'postgres-1')
        ->assertJsonPath('data.namespaces.0.name', 'data');
});

it('publishes the gitops inventory and what it leaves of the memory budget', function (): void {
    config()->set('platform.memory_budget_mib', 4600);
    config()->set('platform.components', [
        ['name' => 'api', 'layer' => 'workloads', 'namespace' => 'apps', 'wave' => 7, 'role' => 'API Laravel', 'memory' => ['request_mib' => 320, 'limit_mib' => 480]],
        ['name' => 'traefik', 'layer' => 'platform', 'namespace' => 'traefik', 'wave' => 1, 'role' => 'Entrée HTTP', 'memory' => ['request_mib' => 80, 'limit_mib' => 150]],
    ]);

    fakeVictoriaMetrics([]);

    $this->getJson('/infrastructure')
        ->assertOk()
        ->assertJsonPath('data.gitops.memory.requested_mib', 400)
        ->assertJsonPath('data.gitops.memory.budget_mib', 4600)
        ->assertJsonPath('data.gitops.memory.headroom_mib', 4200)
        // Ordonné par vague de synchronisation ArgoCD.
        ->assertJsonPath('data.gitops.components.0.name', 'traefik')
        ->assertJsonPath('data.gitops.components.1.name', 'api');
});

it('answers 503 and names the source when VictoriaMetrics is unreachable', function (): void {
    Http::preventStrayRequests();
    Http::fake(['vmsingle.test:8428/*' => Http::failedConnection()]);

    $this->getJson('/infrastructure')
        ->assertStatus(503)
        ->assertJsonPath('source', 'victoriametrics');
});

it('answers 503 when VictoriaMetrics rejects the query', function (): void {
    Http::preventStrayRequests();
    Http::fake(['vmsingle.test:8428/*' => Http::response([
        'status' => 'error',
        'errorType' => 'bad_data',
        'error' => 'unsupported metric name',
    ])]);

    $this->getJson('/infrastructure')
        ->assertStatus(503)
        ->assertJsonPath('source', 'victoriametrics');
});
