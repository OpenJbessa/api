<?php

use App\Services\Stream\EventStream;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('platform.metrics.url', 'http://vmsingle.test:8428');
    config()->set('database.redis.options.prefix', '');
});

/**
 * Un vrai stream Redis : `$emitted` événements écrits, dont `$read` distribués
 * au consommateur `worker-a` sans être encore acquittés.
 */
function streamWithBacklog(int $emitted, int $read = 0): void
{
    config()->set('demo.stream.read_count', max(1, $read));
    config()->set('demo.stream.block_ms', 1);

    $stream = app(EventStream::class);
    $stream->ensureGroup();
    $stream->publish($emitted);

    if ($read > 0) {
        $stream->read('worker-a');
    }
}

function failingRedis(): void
{
    test()->mock(EventStream::class, function ($mock): void {
        $mock->shouldReceive('key')->andReturn('demo:events');
        $mock->shouldReceive('group')->andReturn('workers');
        $mock->shouldReceive('groupState')->andThrow(new RedisException('Connection refused'));
    });
}

/**
 * @param  array<string, list<array<string, mixed>>>  $resultsByQueryFragment
 */
function fakeScalingMetrics(array $resultsByQueryFragment): void
{
    Http::preventStrayRequests();

    Http::fake(['vmsingle.test:8428/api/v1/query*' => function (Request $request) use ($resultsByQueryFragment) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $query = (string) ($parameters['query'] ?? '');

        foreach ($resultsByQueryFragment as $fragment => $results) {
            if (str_contains($query, $fragment)) {
                return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $results]]);
            }
        }

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
    }]);
}

/**
 * @param  array<string, string>  $labels
 * @return array{metric: array<string, string>, value: array{0: int, 1: string}}
 */
function scalingSeries(string $name, array $labels, float $value): array
{
    return ['metric' => ['__name__' => $name, ...$labels], 'value' => [1_790_164_800, (string) $value]];
}

it('reports the stream, the replicas and what KEDA sees', function (): void {
    streamWithBacklog(emitted: 1_200, read: 50);
    fakeScalingMetrics([
        'kube_deployment_spec_replicas' => [
            scalingSeries('kube_deployment_spec_replicas', ['namespace' => 'apps', 'deployment' => 'worker'], 3),
            scalingSeries('kube_deployment_status_replicas_ready', ['namespace' => 'apps', 'deployment' => 'worker'], 2),
            scalingSeries('kube_deployment_status_replicas_available', ['namespace' => 'apps', 'deployment' => 'worker'], 2),
        ],
        'keda_scaler_metrics_value' => [
            scalingSeries('keda_scaler_metrics_value', ['scaledObject' => 'worker', 'scaler' => 'redisStreamsScaler'], 1_150),
        ],
    ]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.key', 'demo:events')
        ->assertJsonPath('data.stream.group', 'workers')
        ->assertJsonPath('data.stream.length', 1_200)
        ->assertJsonPath('data.stream.lag', 1_150)
        ->assertJsonPath('data.stream.pending', 50)
        ->assertJsonPath('data.stream.active_consumers', 1)
        ->assertJsonPath('data.stream.lag_count', 500)
        ->assertJsonPath('data.stream.scaling_up', true)
        ->assertJsonPath('data.worker.replicas.desired', 3)
        ->assertJsonPath('data.worker.replicas.ready', 2)
        ->assertJsonPath('data.worker.at_ceiling', false)
        // 1 150 entrées de retard sur un seuil de 500 : trois réplicas.
        ->assertJsonPath('data.worker.projected_replicas', 3)
        ->assertJsonPath('data.keda.observed_lag', 1_150)
        ->assertJsonPath('data.keda.key_matches', true)
        ->assertJsonPath('meta.unavailable_sources', []);
});

it('keeps the worker at its floor while the lag fits in one replica', function (): void {
    streamWithBacklog(emitted: 20);
    fakeScalingMetrics([]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.lag', 20)
        ->assertJsonPath('data.stream.scaling_up', false)
        ->assertJsonPath('data.worker.projected_replicas', 1);
});

it('never projects more replicas than the memory budget allows', function (): void {
    streamWithBacklog(emitted: 5_000);
    fakeScalingMetrics([
        'kube_deployment_spec_replicas' => [
            scalingSeries('kube_deployment_spec_replicas', ['namespace' => 'apps', 'deployment' => 'worker'], 4),
        ],
    ]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.worker.projected_replicas', 4)
        ->assertJsonPath('data.worker.at_ceiling', true);
});

it('flags the mismatch when the application prefixes the stream KEDA watches', function (): void {
    // Laravel préfixe ses clés d'après APP_NAME quand REDIS_PREFIX n'est pas
    // fixé ; le ScaledObject interroge `demo:events` en dur, ne voit aucun
    // retard, et le worker reste à un réplica sans qu'aucune erreur ne remonte.
    config()->set('database.redis.options.prefix', 'jbessa_database_');
    fakeScalingMetrics([]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.key', 'jbessa_database_demo:events')
        ->assertJsonPath('data.keda.watched_stream', 'demo:events')
        ->assertJsonPath('data.keda.key_matches', false);
});

it('reports an unknown lag when no consumer group exists yet', function (): void {
    fakeScalingMetrics([]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.length', 0)
        ->assertJsonPath('data.stream.lag', null)
        ->assertJsonPath('data.worker.projected_replicas', null);
});

it('still answers with the stream when VictoriaMetrics is unreachable', function (): void {
    streamWithBacklog(emitted: 12);
    Http::preventStrayRequests();
    Http::fake(['vmsingle.test:8428/*' => Http::failedConnection()]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.lag', 12)
        ->assertJsonPath('data.worker.replicas.desired', null)
        ->assertJsonPath('data.keda.observed_lag', null)
        ->assertJsonPath('meta.unavailable_sources', ['victoriametrics']);
});

it('still answers with the replicas when Redis is unreachable', function (): void {
    failingRedis();
    fakeScalingMetrics([
        'kube_deployment_spec_replicas' => [
            scalingSeries('kube_deployment_spec_replicas', ['namespace' => 'apps', 'deployment' => 'worker'], 2),
        ],
    ]);

    $this->getJson('/scaling')
        ->assertOk()
        ->assertJsonPath('data.stream.lag', null)
        ->assertJsonPath('data.worker.replicas.desired', 2)
        ->assertJsonPath('data.worker.projected_replicas', null)
        ->assertJsonPath('meta.unavailable_sources', ['redis']);
});
