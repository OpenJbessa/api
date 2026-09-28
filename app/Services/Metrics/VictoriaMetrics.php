<?php

namespace App\Services\Metrics;

use App\Exceptions\PlatformSourceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Requêtes instantanées sur VictoriaMetrics, qui expose l'API de Prometheus.
 */
class VictoriaMetrics
{
    /**
     * @return Collection<int, Sample>
     *
     * @throws PlatformSourceUnavailableException
     */
    public function query(string $query): Collection
    {
        try {
            $response = $this->configure(Http::baseUrl($this->baseUrl()))
                ->get('/api/v1/query', ['query' => $query]);
        } catch (ConnectionException $exception) {
            throw new PlatformSourceUnavailableException(
                'victoriametrics',
                'VictoriaMetrics est injoignable : '.$exception->getMessage(),
                $exception,
            );
        }

        return $this->samples($response, $query);
    }

    /**
     * Plusieurs requêtes en parallèle, indexées par la clé donnée.
     *
     * @param  array<string, string>  $queries
     * @return array<string, Collection<int, Sample>>
     *
     * @throws PlatformSourceUnavailableException
     */
    public function queryMany(array $queries): array
    {
        $responses = Http::pool(fn (Pool $pool): array => collect($queries)
            ->map(fn (string $query, string $key) => $this->configure($pool->as($key))
                ->get('/api/v1/query', ['query' => $query]))
            ->all());

        return collect($queries)
            ->map(fn (string $query, string $key): Collection => $this->samples($responses[$key] ?? null, $query))
            ->all();
    }

    /**
     * @return Collection<int, Sample>
     *
     * @throws PlatformSourceUnavailableException
     */
    private function samples(mixed $response, string $query): Collection
    {
        if ($response instanceof Throwable) {
            throw new PlatformSourceUnavailableException(
                'victoriametrics',
                'VictoriaMetrics est injoignable : '.$response->getMessage(),
                $response,
            );
        }

        if (! $response instanceof Response || ! $response->successful()) {
            throw new PlatformSourceUnavailableException(
                'victoriametrics',
                sprintf(
                    'VictoriaMetrics a répondu %s pour la requête « %s ».',
                    $response instanceof Response ? $response->status() : 'rien',
                    $query,
                ),
            );
        }

        // Une requête syntaxiquement invalide revient en 200 avec status=error.
        if (($response->json('status') ?? 'error') !== 'success') {
            throw new PlatformSourceUnavailableException(
                'victoriametrics',
                sprintf(
                    'VictoriaMetrics a rejeté la requête « %s » : %s',
                    $query,
                    $response->json('error') ?? 'raison inconnue',
                ),
            );
        }

        return collect((array) ($response->json('data.result') ?? []))
            ->map(fn (array $result): Sample => Sample::fromVector($result))
            ->values();
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl($this->baseUrl())
            ->connectTimeout(config('platform.metrics.connect_timeout'))
            ->timeout(config('platform.metrics.timeout'))
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('platform.metrics.url'), '/');
    }
}
