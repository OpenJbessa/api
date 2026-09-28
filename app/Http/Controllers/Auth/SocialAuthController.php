<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\DemoCapacityReachedException;
use App\Http\Controllers\Controller;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * Connexion à la démo par GitHub ou Google.
 *
 * Routes de routes/web.php, groupe `web` : la session y existe toujours, et
 * Socialite y vérifie le paramètre `state` OAuth. Le cookie est partagé avec
 * le front par SESSION_DOMAIN (.jbessa.tech).
 *
 * Le retour se fait toujours par une redirection vers {FRONTEND_URL}/demo : ce
 * sont des navigations du navigateur, jamais des appels fetch.
 */
class SocialAuthController extends Controller
{
    /**
     * Scopes minimaux : l'identité publique, jamais l'e-mail.
     *
     * @var array<string, list<string>>
     */
    private const SCOPES = [
        'github' => [],
        'google' => ['openid', 'profile'],
    ];

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        return $driver->setScopes(self::SCOPES[$provider])->redirect();
    }

    public function callback(Request $request, string $provider, DemoAccountService $accounts, DemoLogin $login): RedirectResponse
    {
        try {
            $social = Socialite::driver($provider)->user();
        } catch (Throwable $exception) {
            // Refus de l'autorisation, `state` invalide, fournisseur injoignable.
            report($exception);

            return $this->toFrontend('erreur=social');
        }

        try {
            $user = $accounts->fromSocial($provider, $social);
        } catch (DemoCapacityReachedException) {
            return $this->toFrontend('erreur=capacite');
        }

        $login->open($request, $user);

        return $this->toFrontend();
    }

    private function toFrontend(?string $query = null): RedirectResponse
    {
        $url = rtrim((string) config('demo.frontend_url'), '/').'/demo';

        return redirect()->away($query === null ? $url : "{$url}?{$query}");
    }
}
