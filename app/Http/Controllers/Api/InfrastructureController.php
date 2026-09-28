<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlatformSourceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InfrastructureResource;
use App\Services\Platform\ClusterInventory;
use Illuminate\Support\Facades\Cache;

class InfrastructureController extends Controller
{
    /**
     * Composition de la plateforme : ce que gitops déclare, ce qui tourne.
     *
     * VictoriaMetrics est indispensable ici — sans elle il ne resterait que
     * l'inventaire déclaré, c'est-à-dire une recopie de la configuration. Mieux
     * vaut un 503 franc qu'une réponse qui aurait l'air d'un état du cluster.
     *
     * @throws PlatformSourceUnavailableException
     */
    public function __invoke(ClusterInventory $inventory): InfrastructureResource
    {
        $report = Cache::remember(
            'platform:infrastructure',
            config('platform.cache_ttl.infrastructure'),
            fn (): array => $inventory->report(),
        );

        return (new InfrastructureResource($report))->additional([
            'meta' => [
                'sources' => ['victoriametrics', 'kube-state-metrics', 'gitops'],
                'collected_at' => $report['collected_at'],
            ],
        ]);
    }
}
