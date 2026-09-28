<?php

namespace App\Services\Demo;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Index des sessions de chaque compte de démo.
 *
 * Le pilote de session Redis range chaque session sous son seul identifiant :
 * rien ne permet de retrouver les sessions d'un compte à partir de celui-ci.
 * On tient donc, par compte, un ensemble Redis des identifiants de session,
 * pour pouvoir les détruire au moment de la purge.
 *
 * L'ensemble porte un TTL un peu au-delà de l'expiration du compte : il reste
 * évinçable par la politique volatile-lru, et disparaît seul si la purge
 * n'arrivait jamais.
 */
class SessionTracker
{
    /**
     * Marge au-delà de l'expiration du compte, pour que l'index survive
     * jusqu'au balayage de purge du générateur (toutes les 5 minutes).
     */
    private const GRACE_SECONDS = 600;

    public function track(int $userId, string $sessionId, int $secondsRemaining): void
    {
        $key = $this->key($userId);
        $ttl = max(1, $secondsRemaining) + self::GRACE_SECONDS;

        $redis = $this->redis();
        $redis->sadd($key, $sessionId);
        $redis->expire($key, $ttl);
    }

    /**
     * @return list<string>
     */
    public function sessions(int $userId): array
    {
        return array_values((array) $this->redis()->smembers($this->key($userId)));
    }

    /**
     * Lit puis supprime l'index d'un compte.
     *
     * @return list<string>
     */
    public function pull(int $userId): array
    {
        $sessions = $this->sessions($userId);
        $this->redis()->del($this->key($userId));

        return $sessions;
    }

    public function key(int $userId): string
    {
        return "demo:sessions:{$userId}";
    }

    private function redis(): Connection
    {
        return Redis::connection((string) config('demo.redis_connection'));
    }
}
