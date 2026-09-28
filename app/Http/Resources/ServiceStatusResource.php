<?php

namespace App\Http\Resources;

use App\Services\Gatus\ServiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceStatus
 */
class ServiceStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'group' => $this->group,
            'healthy' => $this->healthy,
            'since' => $this->since?->toIso8601String(),
            'uptime_seconds' => $this->uptimeInSeconds(),

            // Vrai quand Gatus n'a pas observé la bascule précédente : la date
            // ci-dessus est alors un plancher — le service est up AU MOINS
            // depuis ce moment, peut-être depuis bien plus longtemps. Cf.
            // ServiceStatus::resolveSince().
            'since_is_approximate' => $this->sinceIsApproximate,

            'last_checked_at' => $this->lastCheckedAt?->toIso8601String(),
            'response_time_ms' => $this->responseTimeMs,
            'http_status' => $this->httpStatus,
            'failed_conditions' => $this->failedConditions,
            'uptime' => (object) $this->uptime,
        ];
    }
}
