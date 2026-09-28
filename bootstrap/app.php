<?php

use App\Exceptions\ErrorResponses;
use App\Http\Middleware\EnsureAccountNotExpired;
use App\Http\Middleware\RequireGrant;
use App\Http\Middleware\RequireMetricsToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Pas de `health: '/up'` : la route intégrée rend une vue Blade, qu'il
        // faudrait compiler sur un système de fichiers en lecture seule. /up
        // est déclarée dans routes/api.php, sans aucune dépendance.
        // Sans préfixe : l'application est seule sur api.jbessa.tech
        // (docs/adr/).
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en mode SPA : les appels du front (SANCTUM_STATEFUL_DOMAINS)
        // reçoivent la session et la protection CSRF du groupe web.
        $middleware->statefulApi();

        // Seul Traefik joint le pod (NetworkPolicy) : ses en-têtes X-Forwarded-*
        // sont fiables, et donnent le schéma https aux URL et aux cookies.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'demo.alive' => EnsureAccountNotExpired::class,
            'grant' => RequireGrant::class,
            'metrics.token' => RequireMetricsToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ErrorResponses::register($exceptions);
    })->create();
