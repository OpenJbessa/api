<?php

namespace App\Services\Demo;

use App\Exceptions\DemoCapacityReachedException;
use App\Models\AccessGrant;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Création des comptes de démonstration et de leurs accès.
 */
class DemoAccountService
{
    /**
     * Clé du verrou consultatif PostgreSQL qui sérialise les créations : sans
     * lui, deux créations simultanées compteraient la même place libre.
     */
    private const CAPACITY_LOCK = 7_210_001;

    public function __construct(private AccountPurger $purger) {}

    /**
     * @param  array{name?: string|null, avatar_url?: string|null}  $attributes
     * @param  (Closure(User): mixed)|null  $within  exécutée dans la même transaction
     *
     * @throws DemoCapacityReachedException
     */
    public function create(array $attributes = [], ?Closure $within = null): User
    {
        return DB::transaction(function () use ($attributes, $within): User {
            DB::select('select pg_advisory_xact_lock(?)', [self::CAPACITY_LOCK]);

            if (User::activeDemo()->count() >= (int) config('demo.max_active_accounts')) {
                throw new DemoCapacityReachedException($this->secondsUntilNextFreeSlot());
            }

            $user = User::create([
                'name' => $this->displayName($attributes['name'] ?? null),
                // Adresse non routable (RFC 2606) : aucune donnée personnelle.
                'email' => 'demo+'.Str::uuid().'@demo.invalid',
                'password' => null,
                'is_demo' => true,
                'expires_at' => now()->addMinutes((int) config('demo.ttl_minutes')),
                'avatar_url' => $this->avatarUrl($attributes['avatar_url'] ?? null),
            ]);

            foreach ((array) config('demo.default_grants') as $ability => $minutes) {
                $this->grant($user, (string) $ability, $minutes === null ? null : (int) $minutes, AccessGrant::VIA_DEFAULT);
            }

            if ($within !== null) {
                $within($user);
            }

            return $user;
        });
    }

    /**
     * Le compte associé à une identité OAuth.
     *
     * Compte encore vivant : repris tel quel, sans prolonger sa durée de vie.
     * Compte expiré mais pas encore purgé : purgé, puis remplacé.
     *
     * @throws DemoCapacityReachedException
     */
    public function fromSocial(string $provider, SocialiteUser $social): User
    {
        $account = SocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $social->getId())
            ->first();

        $user = $account?->user;

        if ($user !== null && ! $user->isExpired()) {
            return $user;
        }

        if ($user !== null && $user->is_demo) {
            $this->purger->purge($user);
        }

        return $this->create(
            ['name' => $social->getNickname() ?: $social->getName(), 'avatar_url' => $social->getAvatar()],
            fn (User $user) => $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_user_id' => (string) $social->getId(),
            ]),
        );
    }

    /**
     * Accorde un accès, jamais au-delà de l'expiration du compte.
     *
     * @param  int|null  $minutes  null : jusqu'à l'expiration du compte
     */
    public function grant(User $user, string $ability, ?int $minutes, string $via): AccessGrant
    {
        $expiresAt = $minutes === null ? $user->expires_at : now()->addMinutes($minutes);

        if ($user->expires_at !== null && ($expiresAt === null || $expiresAt->gt($user->expires_at))) {
            $expiresAt = $user->expires_at;
        }

        return $user->grants()->create([
            'ability' => $ability,
            'granted_via' => $via,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Accès temporaire demandé par le visiteur.
     *
     * @throws ValidationException
     */
    public function elevate(User $user, string $ability): AccessGrant
    {
        $minutes = config("demo.elevatable.{$ability}");

        if (! array_key_exists($ability, (array) config('demo.elevatable'))) {
            throw ValidationException::withMessages([
                'ability' => "L'accès « {$ability} » ne peut pas être demandé.",
            ]);
        }

        return DB::transaction(function () use ($user, $ability, $minutes): AccessGrant {
            // Verrou sur la ligne du compte : deux demandes simultanées ne
            // passent pas toutes deux sous le quota.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($user->activeGrant($ability) !== null) {
                throw ValidationException::withMessages(['ability' => 'Cet accès est déjà actif.']);
            }

            if ($user->elevationsRemaining() <= 0) {
                throw ValidationException::withMessages([
                    'ability' => 'Vous avez utilisé toutes vos demandes d\'accès pour cette session de démonstration.',
                ]);
            }

            return $this->grant($user, $ability, (int) $minutes, AccessGrant::VIA_ELEVATION);
        });
    }

    /**
     * Délai jusqu'à l'expiration du plus ancien compte actif.
     */
    private function secondsUntilNextFreeSlot(): int
    {
        $earliest = User::activeDemo()->min('expires_at');

        if ($earliest === null) {
            return 60;
        }

        return max(1, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($earliest), false)));
    }

    private function displayName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === ''
            ? 'Visiteur '.Str::upper(Str::random(4))
            : Str::limit($name, 60, '');
    }

    /**
     * Seule une URL HTTPS de longueur raisonnable est gardée : l'avatar est
     * affiché par le front, pas téléchargé.
     */
    private function avatarUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 512 || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }
}
