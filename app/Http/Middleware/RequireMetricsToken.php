<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `metrics.token`. GET /metrics n'est servi qu'avec
 * `Authorization: Bearer {METRICS_TOKEN}`.
 *
 * Seconde barrière derrière la NetworkPolicy, qui n'ouvre le port qu'à
 * vmagent. Sans jeton configuré, ou avec un mauvais jeton, la route répond 404
 * plutôt que 401 : elle n'a pas à signaler qu'elle existe.
 */
class RequireMetricsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('demo.metrics.token');
        $given = (string) $request->bearerToken();

        abort_if($expected === '' || ! hash_equals($expected, $given), 404);

        return $next($request);
    }
}
