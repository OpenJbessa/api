<?php

use Illuminate\Support\Facades\Redis;

/*
 * /up est la sonde de vie : elle ne dépend de rien, pour qu'une panne de
 * PostgreSQL ou de Redis ne fasse pas redémarrer l'API en boucle.
 * /ready est la sonde de disponibilité : elle retire le pod du Service tant
 * que ses dépendances ne répondent pas.
 */

/**
 * Rend une dépendance injoignable pour la sonde : port fermé, refus immédiat.
 */
function breakProbe(string $dependency): void
{
    match ($dependency) {
        'database' => config()->set('database.connections.pgsql_probe.port', 1),
        'redis' => config()->set('database.redis.probe.port', 1),
    };

    // RedisManager copie sa configuration à sa création, qui a déjà eu lieu
    // (TestCase vide Redis) : on le fait recréer.
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
}

it('answers ready when PostgreSQL and Redis both respond', function (): void {
    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
});

it('answers 503 not_ready and names the dependency that fails', function (string $dependency, array $checks): void {
    breakProbe($dependency);

    $startedAt = microtime(true);

    $this->getJson('/ready')
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'not_ready')
        ->assertJsonPath('checks', $checks);

    expect(microtime(true) - $startedAt)->toBeLessThan(2.5);
})->with([
    'PostgreSQL' => ['database', ['database' => 'failed', 'redis' => 'ok']],
    'Redis' => ['redis', ['database' => 'ok', 'redis' => 'failed']],
]);

it('keeps /up alive whatever happens to the dependencies', function (): void {
    breakProbe('database');
    breakProbe('redis');

    $this->get('/up')->assertOk();
});
