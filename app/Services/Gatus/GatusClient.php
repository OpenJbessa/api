<?php

namespace App\Services\Gatus;

use App\Exceptions\PlatformSourceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lecture de l'API de Gatus, la page de statut publique de la plateforme.
 */
class GatusClient
{
    /**
     * État de tous les services sondés, ordonné par groupe puis par nom.
     *
     * Plusieurs appels par service, et c'est imposé par l'API de Gatus : la
     * liste `/statuses` ne transporte que les résultats — le champ Uptime porte
     * `json:"-"` et les événements ne sont joints qu'à la fiche d'un service.
     * Or c'est l'historique d'événements qui donne la date de bascule. Les
     * appels partent donc en parallèle, derrière le cache de /status.
     *
     * @return Collection<int, ServiceStatus>
     *
     * @throws PlatformSourceUnavailableException
     */
    public function serviceStatuses(): Collection
    {
        $keys = $this->endpointKeys();

        if ($keys->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, string> $windows */
        $windows = collect((array) config('platform.gatus.uptime_windows'));
        $responses = $this->fetchDetails($keys, $windows);

        return $keys
            ->map(fn (string $key): ServiceStatus => ServiceStatus::fromApi(
                payload: $this->json($responses["status:{$key}"] ?? null) ?? ['key' => $key],
                uptime: $this->uptimes($key, $windows, $responses),
            ))
            ->sortBy(fn (ServiceStatus $status): string => ($status->group ?? '').'|'.$status->name)
            ->values();
    }

    /**
     * Les clés des services sondés. C'est le seul appel dont dépend tout le
     * reste : s'il échoue, /status n'a rien à raconter.
     *
     * @return Collection<int, string>
     *
     * @throws PlatformSourceUnavailableException
     */
    private function endpointKeys(): Collection
    {
        // pageSize=1 : seules les clés nous intéressent ici, les résultats
        // complets arrivent avec la fiche de chaque service.
        $response = $this->get('/api/v1/endpoints/statuses', ['page' => 1, 'pageSize' => 1]);

        if (! $response->successful()) {
            throw new PlatformSourceUnavailableException(
                'gatus',
                "Gatus a répondu {$response->status()} sur /api/v1/endpoints/statuses.",
            );
        }

        return collect((array) ($response->json() ?? []))
            ->pluck('key')
            ->filter()
            ->values();
    }

    /**
     * Fiche et ratios de disponibilité de chaque service, en une seule rafale.
     *
     * Une requête groupée ne lève pas : elle range l'exception à la place de la
     * réponse. On ne refuse donc de répondre que si RIEN n'est exploitable.
     *
     * @param  Collection<int, string>  $keys
     * @param  Collection<int, string>  $windows
     * @return array<string, Response|Throwable>
     *
     * @throws PlatformSourceUnavailableException
     */
    private function fetchDetails(Collection $keys, Collection $windows): array
    {
        $responses = Http::pool(fn (Pool $pool): array => $keys
            ->flatMap(fn (string $key): array => [
                $this->configure($pool->as("status:{$key}"))
                    ->get("/api/v1/endpoints/{$key}/statuses", ['page' => 1, 'pageSize' => 1]),
                ...$windows
                    ->map(fn (string $window) => $this->configure($pool->as("uptime:{$key}:{$window}"))
                        ->get("/api/v1/endpoints/{$key}/uptimes/{$window}"))
                    ->all(),
            ])
            ->all());

        $usable = collect($responses)->contains(
            fn (mixed $response): bool => $response instanceof Response && $response->successful(),
        );

        if (! $usable) {
            throw new PlatformSourceUnavailableException('gatus', 'Aucune fiche de service lisible sur Gatus.');
        }

        return $responses;
    }

    /**
     * L'API d'uptime renvoie un ratio brut, sans enveloppe JSON.
     *
     * @param  Collection<int, string>  $windows
     * @param  array<string, Response|Throwable>  $responses
     * @return array<string, float>
     */
    private function uptimes(string $key, Collection $windows, array $responses): array
    {
        return $windows
            ->mapWithKeys(function (string $window) use ($key, $responses): array {
                $response = $responses["uptime:{$key}:{$window}"] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    return [];
                }

                return [$window => (float) $response->body()];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws PlatformSourceUnavailableException
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            return $this->configure(Http::baseUrl(config('platform.gatus.url')))->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new PlatformSourceUnavailableException(
                'gatus',
                'Gatus est injoignable : '.$exception->getMessage(),
                $exception,
            );
        }
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl(config('platform.gatus.url'))
            ->connectTimeout(config('platform.gatus.connect_timeout'))
            ->timeout(config('platform.gatus.timeout'))
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(mixed $response): ?array
    {
        return $response instanceof Response && $response->successful()
            ? $response->json()
            : null;
    }
}
