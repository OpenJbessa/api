<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErrorResponses;
use App\Http\Controllers\Controller;
use App\Services\Health\ReadinessProbe;
use Illuminate\Http\JsonResponse;

class ReadinessController extends Controller
{
    /**
     * Sonde de disponibilité : 200 si PostgreSQL et Redis répondent, 503 sinon.
     */
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $checks = array_map(fn (bool $healthy): string => $healthy ? 'ok' : 'failed', $probe->check());

        if (in_array('failed', $checks, true)) {
            return ErrorResponses::error(503, 'not_ready', 'Le service démarre ou une de ses dépendances est indisponible.', ['checks' => $checks]);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }
}
