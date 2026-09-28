<?php

namespace App\Services\Gatus;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * État d'un service tel que Gatus le sonde, enrichi de la date de sa dernière
 * bascule.
 *
 * Gatus ne publie pas cette date : il publie une suite d'événements
 * (START, HEALTHY, UNHEALTHY) dont elle se déduit. C'est le travail de
 * `fromApi()`.
 */
readonly class ServiceStatus
{
    /**
     * @param  list<string>  $failedConditions
     * @param  array<string, float>  $uptime  ratio de disponibilité par fenêtre, ex. ['24h' => 0.998]
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $group,
        public bool $healthy,
        public ?CarbonImmutable $since,
        public bool $sinceIsApproximate,
        public ?CarbonImmutable $lastCheckedAt,
        public ?int $responseTimeMs,
        public ?int $httpStatus,
        public array $failedConditions = [],
        public array $uptime = [],
    ) {}

    /**
     * Construit l'état à partir de la charge utile d'un endpoint Gatus.
     *
     * @param  array{key?: string, name?: string, group?: string, results?: list<array<string, mixed>>, events?: list<array<string, mixed>>}  $payload
     * @param  array<string, float>  $uptime
     */
    public static function fromApi(array $payload, array $uptime = []): self
    {
        $results = self::sortedByTimestamp(Arr::get($payload, 'results') ?? []);
        $events = self::sortedByTimestamp(Arr::get($payload, 'events') ?? []);
        $latest = end($results) ?: null;

        $healthy = (bool) Arr::get($latest ?? [], 'success', false);
        [$since, $isApproximate] = self::resolveSince($healthy, $events, $results);

        return new self(
            key: (string) Arr::get($payload, 'key', ''),
            name: (string) Arr::get($payload, 'name', Arr::get($payload, 'key', '')),
            group: Arr::get($payload, 'group'),
            healthy: $healthy,
            since: $since,
            sinceIsApproximate: $isApproximate,
            lastCheckedAt: self::timestamp($latest),
            responseTimeMs: self::durationInMilliseconds($latest),
            httpStatus: $latest === null ? null : Arr::get($latest, 'status'),
            failedConditions: self::failedConditions($latest),
            uptime: $uptime,
        );
    }

    /**
     * Depuis combien de temps le service est dans son état courant, en secondes.
     */
    public function uptimeInSeconds(?CarbonImmutable $now = null): ?int
    {
        // Carbon 3 renvoie un flottant.
        return $this->since === null
            ? null
            : (int) $this->since->diffInSeconds($now ?? CarbonImmutable::now(), absolute: true);
    }

    /**
     * Date de la dernière bascule, et fiabilité de cette date.
     *
     * Gatus stocke son historique en mémoire (observability/gatus/values.yaml) :
     * un redémarrage de son pod repart d'un événement START, et la plus ancienne
     * bascule connue n'est alors plus celle du service mais celle de la sonde.
     * Le second membre du tuple dit exactement cela — la date est un plancher,
     * pas la vérité. Sans ce drapeau, /status annoncerait « up depuis 3 minutes »
     * pour un service qui tourne depuis trois mois.
     *
     * @param  list<array<string, mixed>>  $events  triés du plus ancien au plus récent
     * @param  list<array<string, mixed>>  $results  triés du plus ancien au plus récent
     * @return array{0: ?CarbonImmutable, 1: bool}
     */
    private static function resolveSince(bool $healthy, array $events, array $results): array
    {
        $expected = $healthy ? 'HEALTHY' : 'UNHEALTHY';
        $opposite = $healthy ? 'UNHEALTHY' : 'HEALTHY';

        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (Arr::get($events[$index], 'type') !== $expected) {
                continue;
            }

            $precededByOpposite = collect(array_slice($events, 0, $index))
                ->contains(fn (array $event): bool => Arr::get($event, 'type') === $opposite);

            return [self::timestamp($events[$index]), ! $precededByOpposite];
        }

        return self::sinceFromResults($healthy, $results);
    }

    /**
     * Repli quand aucun événement exploitable n'est disponible : on remonte les
     * résultats tant qu'ils sont dans le même état. La fenêtre de résultats est
     * bornée, donc la date obtenue est toujours un plancher.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array{0: ?CarbonImmutable, 1: bool}
     */
    private static function sinceFromResults(bool $healthy, array $results): array
    {
        $since = null;

        for ($index = count($results) - 1; $index >= 0; $index--) {
            if ((bool) Arr::get($results[$index], 'success', false) !== $healthy) {
                return [$since, false];
            }

            $since = self::timestamp($results[$index]);
        }

        return [$since, $since !== null];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function sortedByTimestamp(array $items): array
    {
        usort($items, fn (array $a, array $b): int => strcmp(
            (string) Arr::get($a, 'timestamp', ''),
            (string) Arr::get($b, 'timestamp', ''),
        ));

        return $items;
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    private static function timestamp(?array $item): ?CarbonImmutable
    {
        $timestamp = Arr::get($item ?? [], 'timestamp');

        return $timestamp === null ? null : CarbonImmutable::parse($timestamp);
    }

    /**
     * Gatus sérialise la durée d'une sonde en nanosecondes, comme toute
     * `time.Duration` de Go.
     *
     * @param  array<string, mixed>|null  $result
     */
    private static function durationInMilliseconds(?array $result): ?int
    {
        $duration = Arr::get($result ?? [], 'duration');

        return $duration === null ? null : (int) round($duration / 1_000_000);
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<string>
     */
    private static function failedConditions(?array $result): array
    {
        return collect((array) (Arr::get($result ?? [], 'conditionResults') ?? []))
            ->reject(fn (array $condition): bool => (bool) Arr::get($condition, 'success', false))
            ->map(fn (array $condition): string => (string) Arr::get($condition, 'condition', ''))
            ->values()
            ->all();
    }
}
