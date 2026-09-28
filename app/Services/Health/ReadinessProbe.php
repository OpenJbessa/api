<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Les dépendances sans lesquelles l'API ne peut rien servir : PostgreSQL et
 * Redis. Chacune a une seconde pour répondre.
 *
 * Réservée à la sonde de disponibilité : un échec retire le pod du Service le
 * temps que la dépendance revienne, sans le redémarrer. La sonde de vie (/up)
 * n'en dépend surtout pas — une panne de PostgreSQL ne doit pas faire
 * redémarrer l'API en boucle.
 */
class ReadinessProbe
{
    private const TIMEOUT_SECONDS = 1.0;

    /**
     * @return array<'database'|'redis', bool>
     */
    public function check(): array
    {
        return [
            'database' => $this->attempt('database', fn () => $this->database()),
            'redis' => $this->attempt('redis', fn () => $this->redis()),
        ];
    }

    /**
     * Selon sa version, libpq peut relever à deux secondes un délai de
     * connexion plus court : le port est donc testé d'abord en TCP, avec une
     * seconde de délai. Un hôte injoignable échoue ainsi en une seconde quelle
     * que soit la version ; la requête est coupée par le serveur à une seconde
     * (statement_timeout).
     */
    private function database(): void
    {
        $this->reachable(
            (string) config('database.connections.pgsql_probe.host'),
            (int) config('database.connections.pgsql_probe.port'),
        );

        try {
            DB::connection('pgsql_probe')->select('select 1');
        } finally {
            DB::disconnect('pgsql_probe');
        }
    }

    private function redis(): void
    {
        try {
            Redis::connection('probe')->ping();
        } finally {
            Redis::purge('probe');
        }
    }

    private function reachable(string $host, int $port): void
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            throw new \RuntimeException("Port {$port} injoignable : {$errorMessage}");
        }

        fclose($socket);
    }

    /**
     * @param  callable(): void  $check
     */
    private function attempt(string $dependency, callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable $exception) {
            Log::warning('readiness.failed', ['dependency' => $dependency, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
