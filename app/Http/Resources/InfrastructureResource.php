<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfrastructureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nodes' => $this->resource['nodes'],
            'namespaces' => $this->resource['namespaces'],
            'workloads' => $this->resource['workloads'],
            'pods' => $this->resource['pods'],
            'gitops' => $this->resource['gitops'],
        ];
    }
}
