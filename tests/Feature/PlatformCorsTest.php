<?php

use Illuminate\Support\Facades\Http;

/*
 * Le front appelle l'API depuis le NAVIGATEUR, donc en cross-origin :
 * jbessa.tech vers api.jbessa.tech. Or Laravel n'applique ses en-têtes CORS
 * qu'aux chemins déclarés dans config/cors.php, et ceux-ci vivent à la racine et
 * non sous /api. Sans cette couverture, l'API répondrait parfaitement et le
 * navigateur jetterait la réponse — une panne invisible dans les journaux.
 *
 * phpunit.xml fixe CORS_ALLOWED_ORIGINS à https://jbessa.tech.
 */

it('lets the front read the platform endpoints, cookies included', function (string $path): void {
    // Les sources sont coupées exprès : l'en-tête doit être là AUSSI sur une
    // réponse d'erreur, sans quoi le navigateur masquerait le 503 qui explique
    // la panne.
    Http::preventStrayRequests();
    Http::fake(['*' => Http::failedConnection()]);

    $this->withHeaders(['Origin' => 'https://jbessa.tech'])
        ->getJson($path)
        ->assertHeader('Access-Control-Allow-Origin', 'https://jbessa.tech')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
})->with(['/status', '/infrastructure', '/scaling']);

it('answers the preflight request a browser sends first', function (string $path, string $method): void {
    $this->call('OPTIONS', $path, server: [
        'HTTP_ORIGIN' => 'https://jbessa.tech',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://jbessa.tech')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE');
})->with([
    ['/status', 'GET'],
    ['/sanctum/csrf-cookie', 'GET'],
    ['/auth/demo', 'POST'],
    ['/me', 'DELETE'],
    ['/demo/burst', 'POST'],
]);

it('gives no CORS header to an origin that is not listed', function (string $path): void {
    $this->call('OPTIONS', $path, server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->withHeaders(['Origin' => 'https://evil.example'])
        ->getJson('/me')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->with(['/status', '/auth/demo', '/me']);

it('leaves the OAuth navigations out of CORS', function (): void {
    $this->withHeaders(['Origin' => 'https://jbessa.tech'])
        ->get('/auth/github/redirect')
        ->assertRedirect()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
