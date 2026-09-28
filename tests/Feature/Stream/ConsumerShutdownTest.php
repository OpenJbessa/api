<?php

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/*
 * Arrêt propre des processus longs, dans un vrai sous-processus : les signaux ne
 * se simulent pas dans celui des tests.
 *
 * Le signal d'arrêt n'est pas forcément SIGTERM. L'image hérite de
 * php:*-fpm la directive STOPSIGNAL SIGQUIT (l'arrêt propre de PHP-FPM), et
 * containerd envoie ce signal-là à l'arrêt du pod. Un processus qui n'écoute
 * que SIGTERM est alors tué au bout du délai de grâce, en plein lot.
 */

/**
 * Processus lancés par le test en cours. Un test qui échoue avant l'arrêt ne
 * doit pas laisser derrière lui un consommateur orphelin, qui lirait le stream
 * des tests suivants.
 *
 * @var list<InvokedProcess>
 */
$GLOBALS['longRunningProcesses'] = [];

afterEach(function (): void {
    foreach ($GLOBALS['longRunningProcesses'] as $process) {
        if ($process->running()) {
            $process->signal(SIGKILL);
        }
    }

    $GLOBALS['longRunningProcesses'] = [];
});

/**
 * Lance une commande longue et attend qu'elle ait annoncé son démarrage.
 *
 * La commande est un tableau et non une chaîne : une chaîne passerait par
 * `sh -c`, et c'est le shell, pas PHP, qui recevrait le signal.
 *
 * @param  list<string>  $command
 */
function startLongRunning(array $command, string $startedLog): InvokedProcess
{
    // Hors de APP_ENV=testing : Laravel n'installe aucun gestionnaire de
    // signal pendant les tests. Base et index Redis restent ceux des tests,
    // hérités de phpunit.xml.
    $process = Process::path(base_path())
        ->env(['APP_ENV' => 'local', 'LOG_CHANNEL' => 'stderr'])
        ->timeout(20)
        ->start($command);

    $GLOBALS['longRunningProcesses'][] = $process;

    $deadline = microtime(true) + 10;

    while (! str_contains($process->latestErrorOutput().$process->errorOutput(), $startedLog)) {
        expect(microtime(true))->toBeLessThan($deadline, 'la commande n\'a pas démarré');
        expect($process->running())->toBeTrue('la commande s\'est arrêtée au démarrage : '.$process->errorOutput());
        usleep(50_000);
    }

    return $process;
}

it('stops the consumer cleanly on every stop signal', function (int $signal): void {
    $process = startLongRunning(['php', 'artisan', 'demo:consume-events', '--consumer=shutdown-test'], 'demo.consumer.started');

    $signalledAt = microtime(true);
    $process->signal($signal);
    $result = $process->wait();

    // Au plus la durée d'un BLOCK (2 s), plus la fin du lot en cours.
    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toContain('demo.consumer.stopped')
        ->and(microtime(true) - $signalledAt)->toBeLessThan(4.0);
})->with([
    'SIGQUIT (STOPSIGNAL de l\'image)' => SIGQUIT,
    'SIGTERM' => SIGTERM,
    'SIGINT' => SIGINT,
]);

it('stops the emitter cleanly on the image stop signal', function (): void {
    $process = startLongRunning(['php', 'artisan', 'demo:emit-events', '--rate=1'], 'demo.emitter.started');

    $process->signal(SIGQUIT);
    $result = $process->wait();

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toContain('demo.emitter.stopped');
});
