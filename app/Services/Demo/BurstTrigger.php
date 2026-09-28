<?php

namespace App\Services\Demo;

use App\Exceptions\BurstAlreadyRunningException;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Rafale d'événements de la démo KEDA, une seule à la fois pour tout le site.
 *
 * Le verrou et la commande ne font qu'un : une clé Redis qui porte la date de
 * fin et expire avec la rafale, posée par SET NX EX. L'opération est atomique
 * et ne dépend d'aucune file ; le générateur (demo:emit-events) accélère tant
 * que la clé existe et revient seul au débit de fond quand elle expire.
 */
class BurstTrigger
{
    /**
     * @return array{rate: int, seconds: int, ends_at: string}
     *
     * @throws BurstAlreadyRunningException
     */
    public function start(): array
    {
        $seconds = (int) config('demo.burst.seconds');
        $endsAt = CarbonImmutable::now()->addSeconds($seconds)->toIso8601String();

        $acquired = $this->redis()->command('set', [$this->key(), $endsAt, ['nx', 'ex' => $seconds]]);

        if (! $acquired) {
            throw new BurstAlreadyRunningException($this->secondsRemaining());
        }

        return [
            'rate' => (int) config('demo.burst.rate'),
            'seconds' => $seconds,
            'ends_at' => $endsAt,
        ];
    }

    public function isRunning(): bool
    {
        return (bool) $this->redis()->exists($this->key());
    }

    /**
     * Temps restant de la rafale en cours, au moins une seconde : c'est la
     * valeur de Retry-After, et zéro inviterait à réessayer trop tôt.
     */
    public function secondsRemaining(): int
    {
        return max(1, (int) $this->redis()->ttl($this->key()));
    }

    private function key(): string
    {
        return (string) config('demo.burst.lock_key');
    }

    private function redis(): Connection
    {
        return Redis::connection((string) config('demo.redis_connection'));
    }
}
