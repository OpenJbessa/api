<?php

use App\Models\User;
use App\Services\Demo\BurstTrigger;
use App\Services\Demo\ExpiredAccountSweeper;
use App\Services\Stream\EventStream;

it('writes the background rate outside a burst', function (): void {
    $this->artisan('demo:emit-events', ['--rate' => 2, '--ticks' => 1])->assertSuccessful();

    $stream = app(EventStream::class);

    expect($stream->counterValue('emitted'))->toBe(2)
        ->and($stream->groupState())->toMatchArray(['exists' => true, 'length' => 2, 'lag' => 2]);
});

it('switches to the burst rate while the burst key exists', function (): void {
    app(BurstTrigger::class)->start();

    $this->artisan('demo:emit-events', ['--rate' => 2, '--ticks' => 1])->assertSuccessful();

    expect(app(EventStream::class)->counterValue('emitted'))->toBe(150);
});

it('keeps the stream within its approximate maximum length', function (): void {
    config()->set('demo.stream.max_length', 100);
    $stream = app(EventStream::class);
    $stream->ensureGroup();

    foreach (range(1, 10) as $batch) {
        $stream->publish(100);
    }

    // MAXLEN ~ coupe par nœuds entiers (100 entrées par défaut) : la longueur
    // reste proche de la borne, jamais proche des 1 000 écrites.
    expect($stream->groupState()['length'])->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(200)
        ->and($stream->counterValue('emitted'))->toBe(1_000);
});

it('purges the abandoned expired accounts as it starts', function (): void {
    $abandoned = User::factory()->expired()->create();
    $alive = User::factory()->demo()->create();
    $owner = User::factory()->create(['expires_at' => now()->subDay()]);

    $this->artisan('demo:emit-events', ['--ticks' => 1])->assertSuccessful();

    $this->assertModelMissing($abandoned);
    $this->assertModelExists($alive);
    $this->assertModelExists($owner);
});

it('purges again once the interval has elapsed', function (): void {
    config()->set('demo.purge_interval_seconds', 2);

    // Vivant au balayage du démarrage (t0), expiré à celui de t0 + 2 s : seul
    // le second balayage peut le supprimer.
    $expiresSoon = User::factory()->demo()->create(['expires_at' => now()->addSecond()]);

    $this->artisan('demo:emit-events', ['--ticks' => 3])->assertSuccessful();

    $this->assertModelMissing($expiresSoon);
});

it('never runs two purges at once', function (): void {
    $expired = User::factory()->expired()->create();

    // Une purge est déjà en cours ailleurs (commande manuelle, autre processus).
    $held = app(ExpiredAccountSweeper::class)->lock();
    expect($held->get())->toBeTrue();

    $this->artisan('demo:emit-events', ['--ticks' => 1])->assertSuccessful();
    $this->assertModelExists($expired);

    $this->artisan('demo:purge-expired')->expectsOutput('Une purge est déjà en cours : rien à faire.')->assertSuccessful();
    $this->assertModelExists($expired);

    $held->release();
    $this->artisan('demo:purge-expired')->expectsOutput('Purgés : 1')->assertSuccessful();
    $this->assertModelMissing($expired);
});
