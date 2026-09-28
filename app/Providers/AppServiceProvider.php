<?php

namespace App\Providers;

use App\Http\ClientIp;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Les accès temporaires alimentent aussi les Gates : $user->can('load:burst').
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_demo) {
                return null;
            }

            return $user->activeGrant($ability) !== null ? true : null;
        });
    }

    /**
     * Tous les limiteurs identifient le visiteur par ClientIp, jamais par
     * $request->ip() : derrière Cloudflare et Traefik, celle-ci serait la même
     * pour tout le monde.
     */
    private function configureRateLimiting(): void
    {
        // Endpoints de plateforme. Seconde barrière derrière leur cache : elle
        // protège le pod lui-même, qui tient dans 480 Mo.
        RateLimiter::for('platform', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('platform:'.ClientIp::of($request)));

        // Créations de comptes : connexion anonyme et départ vers un
        // fournisseur OAuth partagent le même compteur.
        RateLimiter::for('demo-signup', fn (Request $request): Limit => Limit::perHour((int) config('demo.signup_per_hour'))
            ->by('signup:'.ClientIp::of($request)));

        // La clé est recomposée par AccountPurger::elevationLimiterKey(), qui
        // l'efface à la purge : ne pas la modifier sans lui.
        RateLimiter::for('elevate', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('elevate:'.($request->user()?->getAuthIdentifier() ?? ClientIp::of($request))));
    }
}
