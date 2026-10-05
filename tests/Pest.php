<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * En-têtes d'un appel fetch du front. Sanctum n'ouvre la session que pour une
 * origine de SANCTUM_STATEFUL_DOMAINS (phpunit.xml : jbessa.tech).
 *
 * @return array<string, string>
 */
function fromFrontend(string $clientIp = '203.0.113.10'): array
{
    return [
        'Origin' => 'https://jbessa.tech',
        'Referer' => 'https://jbessa.tech/demo',
        'X-Forwarded-For' => $clientIp,
    ];
}

/**
 * Prépare l'appel suivant comme un navigateur : avec le cookie de session reçu
 * dans `$response`, et sans rien de la requête précédente en mémoire.
 *
 * Dans un test, toutes les requêtes partagent la même application : le guard
 * `sanctum` garderait l'utilisateur authentifié d'une requête à l'autre, ce
 * qu'un processus PHP-FPM ne fait jamais. On l'oublie donc explicitement.
 */
function asBrowser(TestResponse $response): TestCase
{
    app('auth')->forgetGuards();

    $cookieName = (string) config('session.cookie');
    $session = $response->getCookie($cookieName);

    return test()
        ->withHeaders(fromFrontend())
        ->withCookie($cookieName, (string) $session?->getValue());
}
