<?php

use App\Services\Stream\EventStream;

beforeEach(function (): void {
    // Pas de traitement simulé ni d'attente : le test vérifie le protocole
    // (lecture, acquittement, reprise), pas le débit.
    config()->set('demo.stream.work_ms', 0);
    config()->set('demo.stream.block_ms', 10);
});

it('reads a batch, acknowledges it and counts it', function (): void {
    $stream = app(EventStream::class);
    $stream->ensureGroup();
    $stream->publish(70);

    $this->artisan('demo:consume-events', ['--consumer' => 'worker-a', '--once' => true])->assertSuccessful();

    // Un lot fait au plus COUNT 50 entrées.
    expect($stream->counterValue('processed'))->toBe(50)
        ->and($stream->groupState())->toMatchArray(['lag' => 20, 'pending' => 0])
        ->and(collect($stream->consumers())->firstWhere('name', 'worker-a'))
        ->toMatchArray(['name' => 'worker-a', 'pending' => 0]);
});

it('takes over the entries a dead consumer left pending', function (): void {
    $stream = app(EventStream::class);
    $stream->ensureGroup();
    $stream->publish(3);

    // Le pod `worker-dead` a lu trois entrées, puis a été tué avant XACK.
    $stream->read('worker-dead');
    expect($stream->groupState()['pending'])->toBe(3);

    // Au-delà du seuil d'inactivité, elles reviennent au premier consommateur
    // qui démarre. Le seuil réel est de 60 s ; le temps de Redis ne se simule
    // pas, on l'abaisse donc à zéro.
    config()->set('demo.stream.claim_idle_ms', 0);

    $this->artisan('demo:consume-events', ['--consumer' => 'worker-b', '--once' => true])->assertSuccessful();

    expect($stream->groupState()['pending'])->toBe(0)
        ->and($stream->counterValue('processed'))->toBe(3)
        ->and(collect($stream->consumers())->firstWhere('name', 'worker-dead')['pending'])->toBe(0);
});

it('leaves recent pending entries to their consumer', function (): void {
    $stream = app(EventStream::class);
    $stream->ensureGroup();
    $stream->publish(3);
    $stream->read('worker-busy');

    $this->artisan('demo:consume-events', ['--consumer' => 'worker-b', '--once' => true])->assertSuccessful();

    expect(collect($stream->consumers())->firstWhere('name', 'worker-busy')['pending'])->toBe(3)
        ->and($stream->counterValue('processed'))->toBe(0);
});

it('creates the consumer group, and does not fail when it already exists', function (): void {
    $stream = app(EventStream::class);

    expect($stream->groupState()['exists'])->toBeFalse();

    $stream->ensureGroup();
    $stream->ensureGroup();

    expect($stream->groupState())->toMatchArray(['exists' => true, 'length' => 0]);
});

it('forgets long-gone consumers, but never one that still holds entries', function (): void {
    config()->set('demo.stream.read_count', 1);
    $stream = app(EventStream::class);
    $stream->ensureGroup();
    $stream->publish(2);

    // `worker-holding` garde une entrée en attente, `worker-done` a tout acquitté.
    $stream->read('worker-holding');
    $stream->acknowledge(array_keys($stream->read('worker-done')));

    // Tous deux sont « disparus depuis longtemps » au regard de ce seuil.
    config()->set('demo.stream.forget_idle_ms', -1);

    expect($stream->forgetDeadConsumers())->toBe(1)
        ->and(collect($stream->consumers())->pluck('name')->all())->toBe(['worker-holding']);
});

it('beats its heartbeat at every turn of the loop', function (): void {
    $heartbeat = sys_get_temp_dir().'/heartbeat-'.uniqid();
    config()->set('demo.stream.heartbeat_path', $heartbeat);

    $this->artisan('demo:consume-events', ['--consumer' => 'worker-a', '--once' => true])->assertSuccessful();

    clearstatcache();
    expect(file_exists($heartbeat))->toBeTrue()
        ->and(time() - filemtime($heartbeat))->toBeLessThan(5);

    unlink($heartbeat);
});
