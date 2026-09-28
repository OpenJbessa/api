<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScalingResource;
use App\Services\Platform\WorkerScaling;
use Illuminate\Support\Facades\Cache;

class ScalingController extends Controller
{
    /**
     * Autoscaling du worker : stream, réplicas, et ce que KEDA en fait.
     *
     * Contrairement aux deux autres, cet endpoint ne rend jamais 503 sur une
     * source manquante : Redis et VictoriaMetrics répondent à deux questions
     * indépendantes, et chacune reste utile sans l'autre. Ce qui manque est
     * annoncé dans `meta.unavailable_sources` plutôt que passé sous silence.
     */
    public function __invoke(WorkerScaling $scaling): ScalingResource
    {
        $report = Cache::remember(
            'platform:scaling',
            config('platform.cache_ttl.scaling'),
            fn (): array => $scaling->report(),
        );

        return (new ScalingResource($report))->additional([
            'meta' => [
                'sources' => ['redis', 'victoriametrics'],
                'unavailable_sources' => $report['unavailable_sources'],
                'collected_at' => $report['collected_at'],
            ],
        ]);
    }
}
