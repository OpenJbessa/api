<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;

/**
 * Un point instantané renvoyé par une requête PromQL.
 */
readonly class Sample
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $name,
        public array $labels,
        public float $value,
    ) {}

    /**
     * @param  array{metric?: array<string, string>, value?: array{0: int|float, 1: string}}  $result
     */
    public static function fromVector(array $result): self
    {
        $labels = self::normalizeLabels($result['metric'] ?? []);

        return new self(
            name: $labels['__name__'] ?? '',
            labels: $labels,
            value: (float) ($result['value'][1] ?? 0),
        );
    }

    public function label(string $name, ?string $default = null): ?string
    {
        return $this->labels[$name] ?? $default;
    }

    public function toInt(): int
    {
        return (int) round($this->value);
    }

    /**
     * Interprète la valeur comme un horodatage Unix, ce que font les séries
     * `kube_*_start_time` et `kube_*_created`.
     */
    public function toTimestamp(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) round($this->value));
    }

    /**
     * Rend leurs vrais noms aux étiquettes préfixées par `exported_`.
     *
     * vmagent pose sur chaque série collectée les étiquettes de sa CIBLE —
     * `namespace`, `pod`, et tout `app.kubernetes.io/*` via le labelmap de
     * observability/victoriametrics/agent-values.yaml. Quand la série porte déjà
     * une étiquette de même nom, la convention Prometheus (honor_labels à faux)
     * garde celle de la cible et renomme l'originale en `exported_<nom>`.
     *
     * Concrètement : `kube_pod_start_time` décrit le pod `api` du namespace
     * `apps`, mais ressort avec namespace="observability" et
     * pod="kube-state-metrics-…" — l'identité du COLLECTEUR — tandis que le pod
     * réellement décrit se retrouve dans exported_namespace et exported_pod.
     * Filtrer sur `namespace` en PromQL ne renverrait donc rien.
     *
     * D'où le parti pris de ce client : interroger les métriques sans sélecteur
     * sur ces étiquettes, remettre les noms d'aplomb ici, et filtrer en PHP. Le
     * cluster tient sur un nœud, le volume de séries ne justifie pas de pari sur
     * la configuration de collecte.
     *
     * @param  array<string, string>  $metric
     * @return array<string, string>
     */
    private static function normalizeLabels(array $metric): array
    {
        $labels = [];
        $exported = [];

        foreach ($metric as $name => $value) {
            if (str_starts_with($name, 'exported_')) {
                $exported[substr($name, strlen('exported_'))] = $value;

                continue;
            }

            $labels[$name] = $value;
        }

        return [...$labels, ...$exported];
    }
}
