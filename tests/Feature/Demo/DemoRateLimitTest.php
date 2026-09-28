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

    // Un autre visiteur, derrière le même Cloudflare, n'est pas pénalisé.
    $this->withHeaders(fromFrontend('198.51.100.8'))->postJson('/auth/demo')->assertCreated();
});

it('counts OAuth departures against the same sign-up limit', function (): void {
    config()->set('demo.signup_per_hour', 5);

    foreach (range(1, 5) as $attempt) {
        $this->withHeaders(['CF-Connecting-IP' => '198.51.100.9'])->get('/auth/github/redirect')->assertRedirect();
    }

    $this->withHeaders(fromFrontend('198.51.100.9'))->postJson('/auth/demo')->assertTooManyRequests();
});

it('reads the visitor address from CF-Connecting-IP, and ignores a malformed one', function (): void {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '10.42.0.12']);

    $request->headers->set('CF-Connecting-IP', '2001:db8::1');
    expect(ClientIp::of($request))->toBe('2001:db8::1');

    $request->headers->set('CF-Connecting-IP', 'not-an-ip');
    expect(ClientIp::of($request))->toBe('10.42.0.12');
});
