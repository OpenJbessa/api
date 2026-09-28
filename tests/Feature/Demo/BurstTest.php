<?php

use App\Models\User;
use App\Services\Demo\DemoAccountService;
use Illuminate\Support\Facades\Redis;

/**
 * Un compte de démo qui a obtenu l'accès load:burst.
 */
function burstCapableAccount(): User
{
    $accounts = app(DemoAccountService::class);
    $user = $accounts->create();
    $accounts->elevate($user, 'load:burst');

    return $user;
}

it('runs a single burst at a time for the whole site', function (): void {
    $this->travelTo('2026-09-27T12:00:00Z');
    [$first, $second] = [burstCapableAccount(), burstCapableAccount()];

    $this->actingAs($first)
        ->postJson('/demo/burst')
        ->assertAccepted()
        ->assertExactJson(['rate' => 150, 'seconds' => 60, 'ends_at' => '2026-09-27T12:01:00+00:00']);

    $response = $this->actingAs($second)
        ->postJson('/demo/burst')
        ->assertConflict()
        ->assertJsonPath('code', 'burst_running')
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60)
        ->and(Redis::connection()->get('demo:burst'))->toBe('2026-09-27T12:01:00+00:00')
        ->and(Redis::connection()->ttl('demo:burst'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('accepts a new burst once the previous one is over', function (): void {
    $user = burstCapableAccount();

    $this->actingAs($user)->postJson('/demo/burst')->assertAccepted();
    $this->postJson('/demo/burst')->assertConflict();

    // Le verrou expire de lui-même : c'est ce qui garantit qu'il ne survit
    // jamais à la rafale.
    expect(Redis::connection()->pttl('demo:burst'))->toBeGreaterThan(0);

    // L'expiration est l'affaire de Redis, pas du temps simulé de Laravel.
    // Attendre qu'elle survienne rendait le test instable (horloge de WSL2) :
    // on la simule.
    Redis::connection()->del('demo:burst');

    $this->postJson('/demo/burst')->assertAccepted();
});
