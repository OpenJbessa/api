<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScalingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'worker' => $this->resource['worker'],
            'stream' => $this->resource['stream'],
            'keda' => $this->resource['keda'],
        ];
    }
}
