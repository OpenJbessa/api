<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlatformSourceUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceStatusResource;
use App\Services\Gatus\GatusClient;
use App\Services\Gatus\ServiceStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StatusController extends Controller
{
    /**
     * État des services sondés par Gatus, et depuis quand.
     *
     * @throws PlatformSourceUnavailableException
     */
    public function __invoke(GatusClient $gatus): AnonymousResourceCollection
    {
        /** @var Collection<int, ServiceStatus> $statuses */
        $statuses = Cache::remember(
            'platform:status',
            config('platform.cache_ttl.status'),
            fn (): Collection => $gatus->serviceStatuses(),
        );

        return ServiceStatusResource::collection($statuses)->additional([
            'meta' => [
                'source' => 'gatus',
                'healthy' => $statuses->every(fn (ServiceStatus $status): bool => $status->healthy),
                'services' => $statuses->count(),
                'degraded' => $statuses
                    ->reject(fn (ServiceStatus $status): bool => $status->healthy)
                    ->map(fn (ServiceStatus $status): string => $status->name)
                    ->values()
                    ->all(),
                'checked_at' => $statuses
                    ->map(fn (ServiceStatus $status) => $status->lastCheckedAt)
                    ->filter()
                    ->max()?->toIso8601String(),
            ],
        ]);
    }
}
