<?php

use App\Exceptions\PermanentAccountException;
use App\Models\User;
use App\Services\Demo\AccountPurger;
use App\Services\Demo\SessionTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;

/**
 * Crée un compte de démo comme le fait le front.
 *
 * @return array{0: User, 1: TestResponse}
 */
function signUpFromFrontend(): array
{
    $response = test()->withHeaders(fromFrontend())->postJson('/auth/demo')->assertCreated();

    return [User::query()->latest('id')->firstOrFail(), $response];
}

it('creates a demo account and opens its session', function (): void {
    $this->travelTo('2026-09-27T12:00:00Z');

    $this->withHeaders(fromFrontend())
        ->postJson('/auth/demo')
        ->assertCreated()
        ->assertExactJsonStructure([
            'name', 'avatar_url', 'is_demo', 'expires_at', 'seconds_remaining', 'server_time',
            'providers', 'grants' => ['*' => ['ability', 'granted_via', 'expires_at']],
            'elevatable' => ['load:burst', 'admin:read'], 'elevations_remaining',
        ])
        ->assertJsonPath('is_demo', true)
        ->assertJsonPath('expires_at', '2026-09-27T12:30:00+00:00')
        ->assertJsonPath('seconds_remaining', 1800)
        ->assertJsonPath('server_time', '2026-09-27T12:00:00+00:00')
        ->assertJsonPath('providers', [])
        ->assertJsonPath('grants.0', ['ability' => 'dashboard:view', 'granted_via' => 'default', 'expires_at' => '2026-09-27T12:30:00+00:00'])
        ->assertJsonPath('elevatable', ['load:burst' => 3, 'admin:read' => 5])
        ->assertJsonPath('elevations_remaining', 3);

    $user = User::query()->sole();

    expect($user->email)->toEndWith('@demo.invalid')
        ->and($user->getAuthPassword())->toBeNull()
        ->and(app(SessionTracker::class)->sessions($user->id))->toHaveCount(1);

    $this->assertAuthenticatedAs($user);
});

it('purges every trace of an expired account', function (): void {
    [$user] = signUpFromFrontend();
    $user->socialAccounts()->create(['provider' => 'github', 'provider_user_id' => '42']);
    $user->createToken('demo');
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);

    $tracker = app(SessionTracker::class);
    [$sessionId] = $tracker->sessions($user->id);
    expect(Session::getHandler()->read($sessionId))->not->toBe('');

    $this->travel(config('demo.ttl_minutes') + 1)->minutes();

    $this->artisan('demo:purge-expired')
        ->expectsOutput('Purgés : 1')
        ->assertSuccessful();

    $this->assertModelMissing($user);
    $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('access_grants', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'tokenable_type' => User::class]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    expect(Session::getHandler()->read($sessionId))->toBe('')
        ->and(Redis::connection()->exists($tracker->key($user->id)))->toBe(0);
});

it('refuses an expired account and purges it within the same request', function (): void {
    [$user, $signUp] = signUpFromFrontend();
    $user->socialAccounts()->create(['provider' => 'github', 'provider_user_id' => '42']);
    $user->createToken('demo');
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);

    $tracker = app(SessionTracker::class);
    [$sessionId] = $tracker->sessions($user->id);

    // Le compte à rebours du front atteint zéro : il appelle /me. Le balayage,
    // lui, n'est pas passé.
    $this->travel(config('demo.ttl_minutes') + 1)->minutes();

    asBrowser($signUp)
        ->getJson('/me')
        ->assertUnauthorized()
        ->assertExactJson([
            'code' => 'demo_expired',
            'message' => 'Votre session de démonstration est terminée. Le compte et ses données ont été supprimés.',
        ]);

    $this->assertModelMissing($user);
    $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('access_grants', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'tokenable_type' => User::class]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    expect(Session::getHandler()->read($sessionId))->toBe('')
        ->and(Redis::connection()->exists($tracker->key($user->id)))->toBe(0);
});

it('lets two simultaneous requests purge the same account without error', function (): void {
    // Deux requêtes chargent le compte avant que l'une d'elles ne le purge :
    // chacune tient sa propre instance du modèle.
    $user = User::factory()->expired()->create();
    $winner = User::query()->findOrFail($user->id);
    $loser = User::query()->findOrFail($user->id);

    $this->actingAs($winner)->getJson('/me')->assertUnauthorized()->assertJsonPath('code', 'demo_expired');

    app('auth')->forgetGuards();

    // La seconde arrive sur un compte déjà supprimé : même réponse, rien ne casse.
    $this->actingAs($loser)->getJson('/me')->assertUnauthorized()->assertJsonPath('code', 'demo_expired');

    $this->assertModelMissing($user);
});

it('deletes the account on request, and a second call breaks nothing', function (): void {
    [$user, $signUp] = signUpFromFrontend();

    asBrowser($signUp)->getJson('/me')->assertOk();
    asBrowser($signUp)->deleteJson('/me')->assertNoContent();
    $this->assertModelMissing($user);

    // Même cookie, rejoué : la session a été détruite avec le compte.
    asBrowser($signUp)->deleteJson('/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    // Le balayage passe ensuite : plus rien à supprimer, et aucune erreur.
    $this->artisan('demo:purge-expired')->expectsOutput('Purgés : 0')->assertSuccessful();
});

it('is idempotent, even on a stale model', function (): void {
    $user = User::factory()->expired()->create();
    $purger = app(AccountPurger::class);

    expect($purger->purge($user))->toBeTrue()
        ->and($purger->purge($user))->toBeFalse();
});

it('clears the cache keys tied to the account', function (): void {
    $user = User::factory()->demo()->create();
    $this->actingAs($user)->postJson('/access/elevate', ['ability' => 'load:burst'])->assertCreated();

    $limiterKey = AccountPurger::elevationLimiterKey($user->id);
    expect((int) RateLimiter::attempts($limiterKey))->toBe(1);

    app(AccountPurger::class)->purge($user);

    expect((int) RateLimiter::attempts($limiterKey))->toBe(0);
});

it('logs the purge without any identifier', function (): void {
    Log::spy();
    $user = User::factory()->expired()->create();

    app(AccountPurger::class)->purge($user);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'demo.account.purged'
            && array_keys($context) === ['lifetime_seconds', 'sessions']
            && is_int($context['sessions']));
});

it('never purges a permanent account', function (): void {
    $owner = User::factory()->create(['expires_at' => now()->subDay()]);

    $this->artisan('demo:purge-expired')->expectsOutput('Purgés : 0')->assertSuccessful();
    $this->assertModelExists($owner);

    // demo.alive ne le considère pas comme expiré, et ne le purge donc pas.
    $this->actingAs($owner)->getJson('/me')->assertOk();
    $this->assertModelExists($owner);

    expect(fn () => app(AccountPurger::class)->purge($owner))->toThrow(PermanentAccountException::class);
    $this->assertModelExists($owner);
});

it('refuses to delete a permanent account through DELETE /me', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->deleteJson('/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'permanent_account');

    $this->assertModelExists($owner);
});

it('closes the session on logout without deleting the account', function (): void {
    [$user, $signUp] = signUpFromFrontend();

    asBrowser($signUp)->postJson('/auth/logout')->assertNoContent();

    asBrowser($signUp)->getJson('/me')->assertUnauthorized();
    $this->assertModelExists($user);
});

it('only opens a session for calls from the front', function (): void {
    $this->postJson('/auth/demo')
        ->assertStatus(400)
        ->assertJsonPath('code', 'origin_not_allowed');

    expect(User::query()->count())->toBe(0);
});
