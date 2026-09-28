<?php

use App\Models\User;
use App\Services\Demo\DemoAccountService;

/**
 * Un compte de démo créé par le service, avec ses accès par défaut.
 */
function demoAccount(): User
{
    return app(DemoAccountService::class)->create();
}

it('refuses without the grant, accepts after elevation, refuses again once it expires', function (): void {
    $user = demoAccount();

    $this->actingAs($user)
        ->postJson('/demo/burst')
        ->assertForbidden()
        ->assertExactJson([
            'code' => 'grant_required',
            'message' => 'Cette action demande un accès temporaire.',
            'ability' => 'load:burst',
            'requestable' => true,
        ]);

    $this->postJson('/access/elevate', ['ability' => 'load:burst'])
        ->assertCreated()
        ->assertJsonPath('ability', 'load:burst')
        ->assertJsonPath('expires_at', now()->addMinutes(3)->toIso8601String());

    $this->postJson('/demo/burst')
        ->assertAccepted()
        ->assertHeader('X-Grant-Expires-At');

    $this->travel(config('demo.elevatable')['load:burst'] + 1)->minutes();

    $this->postJson('/demo/burst')
        ->assertForbidden()
        ->assertJsonPath('code', 'grant_required');
});

it('never grants an access beyond the account that carries it', function (): void {
    $user = demoAccount();

    // Deux minutes de vie restantes pour un accès qui en dure cinq.
    $this->travelTo($user->expires_at->subMinutes(2));

    $this->actingAs($user)
        ->postJson('/access/elevate', ['ability' => 'admin:read'])
        ->assertCreated()
        ->assertJsonPath('expires_at', $user->expires_at->toIso8601String());

    expect($user->grants()->max('expires_at'))->toBe($user->expires_at->toDateTimeString());
});

it('enforces the elevation quota over the whole life of the account', function (): void {
    config()->set('demo.max_elevations', 3);
    $user = demoAccount();
    $this->actingAs($user);

    $this->postJson('/access/elevate', ['ability' => 'load:burst'])->assertCreated();
    $this->postJson('/access/elevate', ['ability' => 'admin:read'])->assertCreated();

    // Les deux accès ont expiré, mais ils restent décomptés du quota.
    $this->travel(6)->minutes();
    $this->postJson('/access/elevate', ['ability' => 'load:burst'])->assertCreated();
    $this->getJson('/me')->assertJsonPath('elevations_remaining', 0);

    $this->travel(4)->minutes();
    $this->postJson('/access/elevate', ['ability' => 'load:burst'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation')
        ->assertJsonPath('errors.ability.0', 'Vous avez utilisé toutes vos demandes d\'accès pour cette session de démonstration.');
});

it('refuses an access that is already active', function (): void {
    $this->actingAs(demoAccount());

    $this->postJson('/access/elevate', ['ability' => 'load:burst'])->assertCreated();
    $this->postJson('/access/elevate', ['ability' => 'load:burst'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.ability.0', 'Cet accès est déjà actif.');
});

it('explains invalid requests in French', function (): void {
    $this->actingAs(demoAccount());

    $this->postJson('/access/elevate', [])
        ->assertUnprocessable()
        ->assertExactJson([
            'code' => 'validation',
            'message' => 'Le champ accès est obligatoire.',
            'errors' => ['ability' => ['Le champ accès est obligatoire.']],
        ]);

    $this->postJson('/access/elevate', ['ability' => 'cluster:admin'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'L\'accès « cluster:admin » ne peut pas être demandé.');
});

it('lets a permanent account through without any temporary access', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/overview')
        ->assertOk()
        ->assertJsonStructure(['active_demo_accounts', 'capacity', 'ttl_minutes', 'burst_running']);
});
