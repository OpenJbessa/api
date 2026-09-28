<?php

namespace App\Services\Stream;

use App\Models\User;

/**
 * Séries de la démo KEDA exposées sur GET /metrics.
 *
 * Tout vient de Redis et de PostgreSQL : le nombre de workers se lit dans
 * XINFO CONSUMERS, sans aucun droit sur l'API Kubernetes.
 */
class DemoMetrics
{
    public function __construct(private EventStream $stream) {}

    /**
     * @return list<array{name: string, type: 'counter'|'gauge', help: string, value: int|null}>
     */
    public function collect(): array
    {
        $group = $this->stream->groupState();

        return [
            [
                'name' => 'demo_events_emitted_total',
                'type' => 'counter',
                'help' => 'Événements écrits dans le stream demo:events.',
                'value' => $this->stream->counterValue('emitted'),
            ],
            [
                'name' => 'demo_events_processed_total',
                'type' => 'counter',
                'help' => 'Événements traités et acquittés par les consommateurs.',
                'value' => $this->stream->counterValue('processed'),
            ],
            [
                'name' => 'demo_stream_lag',
                'type' => 'gauge',
                'help' => 'Retard du consumer group workers (champ lag de XINFO GROUPS), la valeur que suit KEDA.',
                // Absent quand Redis ne sait pas le calculer : mieux vaut un
                // trou dans la courbe qu'un zéro qui mentirait.
                'value' => $group['lag'],
            ],
            [
                'name' => 'demo_active_consumers',
                'type' => 'gauge',
                'help' => 'Consommateurs ayant interrogé le stream depuis moins de 10 secondes.',
                'value' => $this->stream->activeConsumers(),
            ],
            [
                'name' => 'demo_active_accounts',
                'type' => 'gauge',
                'help' => 'Comptes de démonstration vivants.',
                'value' => User::activeDemo()->count(),
            ],
        ];
    }

    /**
     * Format texte d'exposition Prometheus, version 0.0.4.
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->collect() as $metric) {
            $lines[] = "# HELP {$metric['name']} {$metric['help']}";
            $lines[] = "# TYPE {$metric['name']} {$metric['type']}";

            if ($metric['value'] !== null) {
                $lines[] = "{$metric['name']} {$metric['value']}";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
