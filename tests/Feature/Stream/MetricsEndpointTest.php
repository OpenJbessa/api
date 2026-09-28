<?php

use App\Models\User;
use App\Services\Demo\BurstTrigger;

beforeEach(function (): void {
    config()->set('demo.metrics.token', 'vmagent-token');
    config()->set('demo.stream.work_ms', 0);
    config()->set('demo.stream.block_ms', 10);
});

/**
 * @return array<string, string>
 */
function asVmagent(string $token = 'vmagent-token'): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/**
 * Valeurs des séries d'une exposition Prometheus, indexées par nom.
 *
 * @return array<string, float>
 */
function parsePrometheus(string $body): array
{
    $values = [];

    foreach (explode("\n", trim($body)) as $line) {
        if (str_starts_with($line, '#')) {
            expect($line)->toMatch('/^# (HELP|TYPE) [a-zA-Z_:][a-zA-Z0-9_:]* .+$/');

            continue;
        }

        expect($line)->toMatch('/^[a-zA-Z_:][a-zA-Z0-9_:]* -?[0-9]+(\.[0-9]+)?$/');
        [$name, $value] = explode(' ', $line);
        $values[$name] = (float) $value;
    }

    return $values;
}

it('hides the endpoint without the right token', function (?string $configured, ?string $given): void {
    config()->set('demo.metrics.token', $configured);

    $this->withHeaders($given === null ? [] : asVmagent($given))->get('/metrics')->assertNotFound();
})->with([
    'aucun jeton envoyé' => ['vmagent-token', null],
    'mauvais jeton' => ['vmagent-token', 'guess'],
    'aucun jeton configuré' => [null, ''],
]);

it('exposes every series in the Prometheus text format', function (): void {
    $response = $this->withHeaders(asVmagent())->get('/metrics')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

    $body = (string) $response->getContent();

    foreach ([
        'demo_events_emitted_total' => 'counter',
        'demo_events_processed_total' => 'counter',
        'demo_stream_lag' => 'gauge',
        'demo_active_consumers' => 'gauge',
        'demo_active_accounts' => 'gauge',
    ] as $name => $type) {
        expect($body)->toContain("# HELP {$name} ")->toContain("# TYPE {$name} {$type}\n");
    }

    parsePrometheus($body);
});

it('reports consistent values after a simulated burst', function (): void {
    User::factory()->demo()->count(2)->create();
    User::factory()->expired()->create();

    app(BurstTrigger::class)->start();
    $this->artisan('demo:emit-events', ['--ticks' => 1])->assertSuccessful();
    $this->artisan('demo:consume-events', ['--consumer' => 'worker-a', '--once' => true])->assertSuccessful();

    $values = parsePrometheus((string) $this->withHeaders(asVmagent())->get('/metrics')->assertOk()->getContent());

    // 150 écrits pendant la rafale, un lot de 50 traité : 100 de retard.
    expect($values)->toBe([
        'demo_events_emitted_total' => 150.0,
        'demo_events_processed_total' => 50.0,
        'demo_stream_lag' => 100.0,
        'demo_active_consumers' => 1.0,
        'demo_active_accounts' => 2.0,
    ]);
});
