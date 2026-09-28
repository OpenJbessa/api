<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte permanent (propriétaire) ou compte de démonstration éphémère.
 *
 * Un compte de démo porte `is_demo` et une date d'expiration ; son adresse est
 * en @demo.invalid, il n'a pas de mot de passe.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_demo
 * @property CarbonInterface|null $expires_at
 * @property string|null $avatar_url
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['name', 'email', 'password', 'is_demo', 'expires_at', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_demo' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<AccessGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /**
     * @return HasMany<AccessGrant, $this>
     */
    public function activeGrants(): HasMany
    {
        return $this->grants()->active();
    }

    public function activeGrant(string $ability): ?AccessGrant
    {
        return $this->activeGrants()
            ->where('ability', $ability)
            ->latest('expires_at')
            ->first();
    }

    /**
     * Élévations encore permises : le quota vaut pour toute la vie du compte,
     * accès expirés compris.
     */
    public function elevationsRemaining(): int
    {
        $used = $this->grants()->where('granted_via', AccessGrant::VIA_ELEVATION)->count();

        return max(0, (int) config('demo.max_elevations') - $used);
    }

    /**
     * Seul un compte de démo expire : un compte permanent n'est jamais refusé
     * ni purgé, quelle que soit sa colonne expires_at.
     */
    public function isExpired(): bool
    {
        return $this->is_demo && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function secondsRemaining(): ?int
    {
        return $this->expires_at === null
            ? null
            : max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function expiredDemo(Builder $query): void
    {
        $query->where('is_demo', true)->where('expires_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeDemo(Builder $query): void
    {
        $query->where('is_demo', true)->where('expires_at', '>', now());
    }
}
