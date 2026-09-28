<?php

namespace App\Services\Stream;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Redis;

/**
 * Le stream Redis de la démo KEDA et son consumer group.
 *
 * Toutes les commandes de stream passent par le client phpredis natif : la
 * connexion Laravel n'en expose aucune avec une signature fiable.
 *
 * Les clés ne sont pas préfixées (REDIS_PREFIX vide) : le ScaledObject KEDA
 * les interroge écrites en dur.
 */
class EventStream
{
    /**
     * Types d'événements synthétiques : de quoi donner un air plausible aux
     * entrées, sans rien de réel.
     */
    private const EVENT_TYPES = ['order.created', 'payment.captured', 'page.viewed', 'cart.updated'];

    /**
     * Crée le consumer group, et le stream avec lui (MKSTREAM). Sans erreur si
     * le group existe déjà.
     *
     * Le group part de l'origine du stream (`0`) : les événements écrits avant
     * le premier consommateur sont traités, pas ignorés.
     */
    public function ensureGroup(): void
    {
        $client = $this->client();

        if ($client->xGroup('CREATE', $this->key(), $this->group(), '0', true) === false) {
            $error = (string) $client->getLastError();
            $client->clearLastError();

            if (! str_starts_with($error, 'BUSYGROUP')) {
                throw new \RuntimeException("Création du consumer group impossible : {$error}");
            }
        }
    }

    /**
     * Écrit `$count` événements en un seul aller-retour (pipeline), avec
     * XADD MAXLEN ~ pour borner la mémoire, et incrémente le compteur émis.
     */
    public function publish(int $count, bool $burst = false): int
    {
        if ($count <= 0) {
            return 0;
        }

        $emittedAt = (string) CarbonImmutable::now()->getTimestampMs();
        $maxLength = (int) config('demo.stream.max_length');

        $pipe = $this->client()->pipeline();

        for ($index = 0; $index < $count; $index++) {
            $pipe->xAdd($this->key(), '*', [
                'type' => self::EVENT_TYPES[$index % count(self::EVENT_TYPES)],
                'burst' => $burst ? '1' : '0',
                'emitted_at' => $emittedAt,
            ], $maxLength, true);
        }

        $pipe->incrBy($this->counter('emitted'), $count);
        $pipe->exec();

        return $count;
    }

    /**
     * Reprend les entrées restées en attente chez un consommateur mort, par
     * XAUTOCLAIM, jusqu'à épuisement.
     *
     * @return array<string, array<string, string>> entrées reprises, par identifiant
     */
    public function claimStale(string $consumer): array
    {
        $claimed = [];
        $cursor = '0-0';
        $minIdle = (int) config('demo.stream.claim_idle_ms');
        $count = (int) config('demo.stream.read_count');

        do {
            $result = $this->client()->xAutoClaim($this->key(), $this->group(), $consumer, $minIdle, $cursor, $count);

            if (! is_array($result)) {
                break;
            }

            $cursor = (string) ($result[0] ?? '0-0');
            $claimed += (array) ($result[1] ?? []);
        } while ($cursor !== '0-0');

        return $claimed;
    }

    /**
     * XREADGROUP des nouvelles entrées, en bloquant au plus `block_ms`.
     *
     * @return array<string, array<string, string>> entrées lues, par identifiant
     */
    public function read(string $consumer): array
    {
        $block = max(1, (int) config('demo.stream.block_ms'));

        $result = $this->client()->xReadGroup(
            $this->group(),
            $consumer,
            [$this->key() => '>'],
            (int) config('demo.stream.read_count'),
            $block,
        );

        return is_array($result) ? (array) ($result[$this->key()] ?? []) : [];
    }

    /**
     * XACK des entrées traitées, et incrément du compteur traité, en un seul
     * aller-retour.
     *
     * @param  list<string>  $ids
     */
    public function acknowledge(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $pipe = $this->client()->pipeline();
        $pipe->xAck($this->key(), $this->group(), $ids);
        $pipe->incrBy($this->counter('processed'), count($ids));
        $results = $pipe->exec();

        return is_array($results) ? (int) ($results[0] ?? 0) : 0;
    }

    /**
     * Retire du group les consommateurs disparus depuis longtemps et sans
     * entrée en attente. Un consommateur qui a encore des entrées n'est
     * jamais retiré : elles seraient perdues pour XAUTOCLAIM.
     */
    public function forgetDeadConsumers(): int
    {
        $forgotten = 0;
        $threshold = (int) config('demo.stream.forget_idle_ms');

        foreach ($this->consumers() as $consumer) {
            if ($consumer['pending'] === 0 && $consumer['idle'] > $threshold) {
                $this->client()->xGroup('DELCONSUMER', $this->key(), $this->group(), $consumer['name']);
                $forgotten++;
            }
        }

        return $forgotten;
    }

    /**
     * État du group, tel que XINFO GROUPS le rapporte. `lag` peut être null
     * quand Redis ne sait pas le calculer (entrées supprimées par MAXLEN avant
     * lecture).
     *
     * @return array{exists: bool, length: int, lag: int|null, pending: int}
     */
    public function groupState(): array
    {
        $client = $this->client();
        $groups = $client->xInfo('GROUPS', $this->key());

        if (! is_array($groups)) {
            $client->clearLastError();

            return ['exists' => false, 'length' => 0, 'lag' => null, 'pending' => 0];
        }

        $group = collect($groups)->first(fn (mixed $group): bool => is_array($group) && ($group['name'] ?? null) === $this->group());

        return [
            'exists' => is_array($group),
            'length' => (int) $client->xLen($this->key()),
            'lag' => is_array($group) && isset($group['lag']) ? (int) $group['lag'] : null,
            'pending' => is_array($group) ? (int) ($group['pending'] ?? 0) : 0,
        ];
    }

    /**
     * Consommateurs du group, d'après XINFO CONSUMERS. `idle` : millisecondes
     * depuis la dernière interrogation du stream par ce consommateur.
     *
     * @return list<array{name: string, pending: int, idle: int}>
     */
    public function consumers(): array
    {
        $client = $this->client();
        $consumers = $client->xInfo('CONSUMERS', $this->key(), $this->group());

        if (! is_array($consumers)) {
            $client->clearLastError();

            return [];
        }

        return array_values(array_map(fn (array $consumer): array => [
            'name' => (string) ($consumer['name'] ?? ''),
            'pending' => (int) ($consumer['pending'] ?? 0),
            'idle' => (int) ($consumer['idle'] ?? PHP_INT_MAX),
        ], array_filter($consumers, 'is_array')));
    }

    /**
     * Consommateurs vivants : le nombre de workers vu depuis Redis, sans aucun
     * droit sur l'API Kubernetes.
     */
    public function activeConsumers(): int
    {
        $threshold = (int) config('demo.stream.active_idle_ms');

        return count(array_filter($this->consumers(), fn (array $consumer): bool => $consumer['idle'] < $threshold));
    }

    /**
     * @param  'emitted'|'processed'  $name
     */
    public function counterValue(string $name): int
    {
        return (int) $this->client()->get($this->counter($name));
    }

    public function key(): string
    {
        return (string) config('demo.stream.key');
    }

    public function group(): string
    {
        return (string) config('demo.stream.group');
    }

    /**
     * @param  'emitted'|'processed'  $name
     */
    private function counter(string $name): string
    {
        return (string) config("demo.stream.counters.{$name}");
    }

    private function client(): Redis
    {
        /** @var Redis $client */
        $client = RedisFacade::connection((string) config('demo.redis_connection'))->client();

        return $client;
    }
}
