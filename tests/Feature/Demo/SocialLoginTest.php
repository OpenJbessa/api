<?php

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Identité GitHub simulée. Le faux porte un e-mail et un jeton, comme le vrai :
 * c'est ce qui permet de vérifier qu'aucun des deux n'est conservé.
 */
function fakeGithubIdentity(string $id = '5550001'): void
{
    Socialite::fake('github', SocialiteUser::fake([
        'id' => $id,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.example',
        'avatar' => 'https://avatars.example/octocat.png',
        'token' => 'gho_secret',
    ]));
}

it('creates a demo account on the first login and sends the visitor back to the front', function (): void {
    fakeGithubIdentity();

    $this->get('/auth/github/callback')->assertRedirect('https://jbessa.tech/demo');

    $user = User::query()->sole();

    expect($user->is_demo)->toBeTrue()
        ->and($user->name)->toBe('octocat')
        ->and($user->email)->toEndWith('@demo.invalid')
        ->and($user->avatar_url)->toBe('https://avatars.example/octocat.png')
        ->and($user->socialAccounts()->sole()->only(['provider', 'provider_user_id']))
        ->toBe(['provider' => 'github', 'provider_user_id' => '5550001']);

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseMissing('users', ['email' => 'octocat@github.example']);

    // Ni l'e-mail ni le jeton OAuth ne sont écrits nulle part en base.
    $dump = json_encode([User::query()->get()->makeVisible(['password', 'remember_token']), SocialAccount::query()->get()]);
    expect($dump)->not->toContain('octocat@github.example')->not->toContain('gho_secret');
});

it('reuses a living account on the next login without extending it', function (): void {
    fakeGithubIdentity();
    $this->get('/auth/github/callback');
    $expiresAt = User::query()->sole()->expires_at;

    app('auth')->forgetGuards();
    $this->travel(10)->minutes();
    $this->get('/auth/github/callback')->assertRedirect('https://jbessa.tech/demo');

    expect(User::query()->sole()->expires_at->equalTo($expiresAt))->toBeTrue();
});

it('purges an expired account and starts a fresh one', function (): void {
    $expired = User::factory()->expired()->create();
    $expired->socialAccounts()->create(['provider' => 'github', 'provider_user_id' => '5550001']);
    fakeGithubIdentity('5550001');

    $this->get('/auth/github/callback')->assertRedirect('https://jbessa.tech/demo');

    $this->assertModelMissing($expired);
    $fresh = User::query()->sole();
    expect($fresh->isExpired())->toBeFalse()
        ->and($fresh->socialAccounts()->sole()->provider_user_id)->toBe('5550001');
});

it('sends the visitor back with erreur=social when the provider fails', function (): void {
    Socialite::fake('github', fn () => throw new InvalidStateException);

    $this->get('/auth/github/callback')->assertRedirect('https://jbessa.tech/demo?erreur=social');

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('sends the visitor back with erreur=capacite when the demo is full', function (): void {
    config()->set('demo.max_active_accounts', 0);
    fakeGithubIdentity();

    $this->get('/auth/github/callback')->assertRedirect('https://jbessa.tech/demo?erreur=capacite');
});

it('asks the providers for the public identity only', function (string $provider, string $expectedScope): void {
    config()->set("services.{$provider}", ['client_id' => 'id', 'client_secret' => 'secret', 'redirect' => 'https://api.jbessa.tech/cb']);

    $location = $this->get("/auth/{$provider}/redirect")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['scope'] ?? '')->toBe($expectedScope);
})->with([
    'GitHub, sans user:email' => ['github', ''],
    'Google, sans email' => ['google', 'openid profile'],
]);

it('knows no other provider', function (): void {
    $this->get('/auth/gitlab/redirect')->assertNotFound();
});
