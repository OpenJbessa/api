<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BurstAlreadyRunningException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Demo\BurstTrigger;
use Illuminate\Http\JsonResponse;

class DemoActionsController extends Controller
{
    /**
     * Déclenche la rafale de la démo KEDA. Accès `load:burst` requis.
     *
     * @throws BurstAlreadyRunningException
     */
    public function burst(BurstTrigger $burst): JsonResponse
    {
        return response()->json($burst->start(), 202);
    }

    /**
     * Panneau d'administration factice. Accès `admin:read` requis. Chiffres
     * agrégés uniquement, rien qui désigne un visiteur.
     */
    public function adminOverview(BurstTrigger $burst): JsonResponse
    {
        return response()->json([
            'active_demo_accounts' => User::activeDemo()->count(),
            'capacity' => (int) config('demo.max_active_accounts'),
            'ttl_minutes' => (int) config('demo.ttl_minutes'),
            'burst_running' => $burst->isRunning(),
        ]);
    }
}
