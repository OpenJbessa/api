<?php

use App\Http\ClientIp;
use Illuminate\Http\Request;

it('answers 429 beyond DEMO_SIGNUP_PER_HOUR sign-ups from the same address', function (): void {
    expect(config('demo.signup_per_hour'))->toBe(10);

    foreach (range(1, 10) as $attempt) {
        $this->withHeaders(fromFrontend('198.51.100.7'))->postJson('/auth/demo')->assertCreated();
        app('auth')->forgetGuards();
    }

    $this->withHeaders(fromFrontend('198.51.100.7'))
        ->postJson('/auth/demo')
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    // Un autre visiteur, derrière Traefik, n'est pas pénalisé.
    $this->withHeaders(fromFrontend('198.51.100.8'))->postJson('/auth/demo')->assertCreated();
});

it('counts OAuth departures against the same sign-up limit', function (): void {
    config()->set('demo.signup_per_hour', 5);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get('/auth/github/redirect')->assertRedirect();
    }

    $this->withHeaders(fromFrontend('198.51.100.9'))->postJson('/auth/demo')->assertTooManyRequests();
});
it('ignore un en-tête CF-Connecting-IP fourni par le client', function (): void {
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.5']);
    $request->headers->set('CF-Connecting-IP', '198.51.100.7');

    expect(ClientIp::of($request))->toBe('203.0.113.5');
});
