<?php

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('platform.gatus.url', 'http://gatus.test:8080');
    config()->set('platform.gatus.uptime_windows', ['24h', '7d']);
});

/**
 * Fiche d'un service telle que Gatus la renvoie sur
 * /api/v1/endpoints/{key}/statuses.
 *
 * @param  list<array{type: string, timestamp: string}>  $events
 * @return array<string, mixed>
 */
function gatusEndpoint(string $key, string $name, string $group, bool $success, array $events, string $checkedAt = '2026-09-23T11:59:00Z'): array
{
    return [
        'key' => $key,
        'name' => $name,
        'group' => $group,
        'results' => [[
            'status' => $success ? 200 : 503,
            'success' => $success,
            'timestamp' => $checkedAt,
            'duration' => 132_000_000,
            'conditionResults' => [
                ['condition' => '[STATUS] == 200', 'success' => $success],
                ['condition' => '[RESPONSE_TIME] < 2000', 'success' => true],
            ],
        ]],
        'events' => $events,
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $endpoints
 */
function fakeGatus(array $endpoints, float $uptime = 0.998): void
{
    Http::preventStrayRequests();

    $stubs = [
        'gatus.test:8080/api/v1/endpoints/statuses*' => Http::response(
            collect($endpoints)->map(fn (array $endpoint): array => ['key' => $endpoint['key']])->values()->all(),
        ),
    ];

    foreach ($endpoints as $endpoint) {
        $stubs["gatus.test:8080/api/v1/endpoints/{$endpoint['key']}/statuses*"] = Http::response($endpoint);
        $stubs["gatus.test:8080/api/v1/endpoints/{$endpoint['key']}/uptimes/*"] = Http::response((string) $uptime);
    }

    Http::fake($stubs);
}

it('lists every service Gatus probes with how long it has been up', function (): void {
    $this->travelTo('2026-09-23T12:00:00Z');

    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [
            ['type' => 'START', 'timestamp' => '2026-09-01T00:00:00Z'],
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z'],
            ['type' => 'UNHEALTHY', 'timestamp' => '2026-09-20T10:00:00Z'],
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-20T10:05:00Z'],
        ]),
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Site')
        ->assertJsonPath('data.0.group', 'Public')
        ->assertJsonPath('data.0.healthy', true)
        ->assertJsonPath('data.0.since', '2026-09-20T10:05:00+00:00')
        ->assertJsonPath('data.0.uptime_seconds', 266_100)
        ->assertJsonPath('data.0.response_time_ms', 132)
        ->assertJsonPath('data.0.http_status', 200)
        ->assertJsonPath('data.0.failed_conditions', [])
        ->assertJsonPath('data.0.uptime.24h', 0.998)
        ->assertJsonPath('meta.healthy', true)
        ->assertJsonPath('meta.services', 1);
});

it('dates the uptime from the last observed recovery, not from the first probe', function (): void {
    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [
            ['type' => 'START', 'timestamp' => '2026-09-01T00:00:00Z'],
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z'],
            ['type' => 'UNHEALTHY', 'timestamp' => '2026-09-20T10:00:00Z'],
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-20T10:05:00Z'],
        ]),
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('data.0.since', '2026-09-20T10:05:00+00:00')
        ->assertJsonPath('data.0.since_is_approximate', false);
});

it('marks the uptime as a floor when Gatus never observed an outage', function (): void {
    // Le stockage de Gatus est en mémoire : après un redémarrage de sa sonde,
    // le premier HEALTHY date de la sonde et non du service.
    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [
            ['type' => 'START', 'timestamp' => '2026-09-23T11:00:00Z'],
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-23T11:00:30Z'],
        ]),
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('data.0.since', '2026-09-23T11:00:30+00:00')
        ->assertJsonPath('data.0.since_is_approximate', true);
});

it('reports a failing service with the conditions it broke', function (): void {
    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z'],
        ]),
        gatusEndpoint('interne_argocd', 'ArgoCD', 'Interne', false, [
            ['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z'],
            ['type' => 'UNHEALTHY', 'timestamp' => '2026-09-23T11:30:00Z'],
        ]),
    ]);

    $this->getJson('/status')
        ->assertOk()
        // Groupe « Interne » avant « Public » : cf. le test d'ordre ci-dessous.
        ->assertJsonPath('data.0.name', 'ArgoCD')
        ->assertJsonPath('data.0.healthy', false)
        ->assertJsonPath('data.0.since', '2026-09-23T11:30:00+00:00')
        ->assertJsonPath('data.0.failed_conditions', ['[STATUS] == 200'])
        ->assertJsonPath('meta.healthy', false)
        ->assertJsonPath('meta.degraded', ['ArgoCD']);
});

it('orders services by group then by name', function (): void {
    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z']]),
        gatusEndpoint('interne_argocd', 'ArgoCD', 'Interne', true, [['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z']]),
        gatusEndpoint('public_api', 'API', 'Public', true, [['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z']]),
    ]);

    $this->getJson('/status')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'ArgoCD')
        ->assertJsonPath('data.1.name', 'API')
        ->assertJsonPath('data.2.name', 'Site');
});

it('answers 503 and names the source when Gatus is unreachable', function (): void {
    Http::preventStrayRequests();
    Http::fake(['gatus.test:8080/*' => Http::failedConnection()]);

    $this->getJson('/status')
        ->assertStatus(503)
        ->assertJsonPath('source', 'gatus');
});

it('answers 503 when Gatus returns an error status', function (): void {
    Http::preventStrayRequests();
    Http::fake(['gatus.test:8080/*' => Http::response(status: 502)]);

    $this->getJson('/status')
        ->assertStatus(503)
        ->assertJsonPath('source', 'gatus');
});

it('serves the cached answer instead of probing Gatus on every request', function (): void {
    fakeGatus([
        gatusEndpoint('public_site', 'Site', 'Public', true, [['type' => 'HEALTHY', 'timestamp' => '2026-09-01T00:00:30Z']]),
    ]);

    $this->getJson('/status')->assertOk();
    $this->getJson('/status')->assertOk();

    // Une liste, une fiche, deux fenêtres d'uptime : la seconde requête HTTP
    // entrante ne doit rien avoir ajouté.
    Http::assertSentCount(4);
});
