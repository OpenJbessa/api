<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\DemoActionsController;
use App\Http\Controllers\Api\InfrastructureController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\ReadinessController;
use App\Http\Controllers\Api\ScalingController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoints de plateforme
|--------------------------------------------------------------------------
|
| Publics et en lecture seule : ils décrivent une infrastructure déjà exposée
| par status.jbessa.tech et par la documentation. Aucun préfixe `/api` —
| l'application vit seule sur api.jbessa.tech.
|
| La limite de débit est une seconde barrière, derrière le cache : les réponses
| sont mémorisées quelques secondes, si bien qu'une rafale n'atteint ni Gatus
| ni VictoriaMetrics. Elle protège le pod de l'API lui-même, qui tient dans
| 480 Mo sur un nœud partagé.
|
*/

Route::middleware('throttle:platform')->group(function (): void {
    Route::get('/status', StatusController::class)->name('platform.status');
    Route::get('/infrastructure', InfrastructureController::class)->name('platform.infrastructure');
    Route::get('/scaling', ScalingController::class)->name('platform.scaling');
});

/*
|--------------------------------------------------------------------------
| Démo à comptes éphémères
|--------------------------------------------------------------------------
|
| Authentifiée par le cookie de session (Sanctum, mode SPA) : le front appelle
| d'abord /sanctum/csrf-cookie, puis envoie l'en-tête X-XSRF-TOKEN. Les routes
| OAuth, qui sont des navigations, vivent dans routes/web.php.
|
*/

Route::post('/auth/demo', DemoLoginController::class)
    ->middleware('throttle:demo-signup')
    ->name('demo.login');

Route::middleware(['auth:sanctum', 'demo.alive'])->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('demo.me');
    Route::delete('/me', [MeController::class, 'destroy'])->name('demo.me.destroy');
    Route::post('/auth/logout', LogoutController::class)->name('demo.logout');

    Route::post('/access/elevate', [AccessController::class, 'elevate'])
        ->middleware('throttle:elevate')
        ->name('demo.elevate');

    Route::post('/demo/burst', [DemoActionsController::class, 'burst'])
        ->middleware('grant:load:burst')
        ->name('demo.burst');

    Route::get('/admin/overview', [DemoActionsController::class, 'adminOverview'])
        ->middleware('grant:admin:read')
        ->name('demo.admin.overview');
});

/*
|--------------------------------------------------------------------------
| Métriques
|--------------------------------------------------------------------------
|
| Lues par vmagent directement sur le pod, jamais par l'ingress : nginx refuse
| /metrics à toute requête passée par Traefik, la NetworkPolicy n'ouvre le
| port qu'à vmagent, et l'application exige un jeton.
|
*/

Route::get('/metrics', MetricsController::class)
    ->middleware('metrics.token')
    ->name('metrics');

/*
|--------------------------------------------------------------------------
| Sondes
|--------------------------------------------------------------------------
|
| /up (vie) ne touche à rien : ni base, ni Redis, ni vue, ni disque. Une panne
| de dépendance ne doit pas faire redémarrer l'API en boucle.
| /ready (disponibilité) vérifie PostgreSQL et Redis, une seconde chacun.
|
*/

Route::get('/up', fn (): JsonResponse => response()->json(['status' => 'ok']))->name('up');
Route::get('/ready', ReadinessController::class)->name('ready');
