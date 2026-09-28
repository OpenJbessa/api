<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stream\DemoMetrics;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    /**
     * Séries de la démo KEDA, lues par vmagent.
     */
    public function __invoke(DemoMetrics $metrics): Response
    {
        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
