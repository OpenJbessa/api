<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Format unique des erreurs de l'API : JSON `{ code, message }`, message en
 * français et sans jargon technique, complété au besoin (en-tête Retry-After,
 * champs `errors`, `source`…).
 *
 * `code` est la clé stable sur laquelle le front décide ; `message` est fait
 * pour être affiché tel quel.
 *
 * Seules les routes du groupe `web` (OAuth, page d'accueil) gardent le rendu
 * HTML de Laravel : ce sont des navigations du navigateur.
 */
final class ErrorResponses
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => self::wantsJson($request));

        $exceptions->render(fn (DemoCapacityReachedException $exception) => self::error(
            503,
            'demo_capacity',
            'Toutes les places de démonstration sont occupées. Réessayez dans quelques minutes.',
            headers: ['Retry-After' => (string) $exception->retryAfterSeconds],
        ));

        $exceptions->render(fn (BurstAlreadyRunningException $exception) => self::error(
            409,
            'burst_running',
            'Une rafale est déjà en cours. Réessayez quand elle sera terminée.',
            headers: ['Retry-After' => (string) $exception->retryAfterSeconds],
        ));

        // Une source injoignable n'est pas une erreur applicative : l'API va
        // bien, c'est l'état du cluster qu'elle ne peut pas rapporter. 503 le
        // dit, et `source` désigne le coupable — le plus souvent une sortie
        // réseau fermée plutôt qu'une panne.
        $exceptions->render(fn (PlatformSourceUnavailableException $exception) => self::error(
            503,
            'source_unavailable',
            $exception->getMessage(),
            ['source' => $exception->source],
        ));

        $exceptions->render(fn (AuthenticationException $exception, Request $request) => self::wantsJson($request)
            ? self::error(401, 'unauthenticated', 'Vous n\'êtes pas connecté.')
            : null);

        $exceptions->render(fn (ValidationException $exception, Request $request) => self::wantsJson($request)
            ? self::error(422, 'validation', $exception->getMessage(), ['errors' => $exception->errors()])
            : null);

        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request) => self::wantsJson($request)
            ? self::error(
                429,
                'too_many_requests',
                'Trop de tentatives. Patientez un moment avant de réessayer.',
                headers: $exception->getHeaders(),
            )
            : null);

        $exceptions->render(fn (HttpExceptionInterface $exception, Request $request) => self::wantsJson($request)
            ? self::httpError($exception)
            : null);

        // Dernier recours, hors mode debug : une erreur imprévue ne doit ni
        // sortir du format, ni exposer le détail de ce qui a cassé.
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if ($exception instanceof HttpResponseException || config('app.debug') || ! self::wantsJson($request)) {
                return null;
            }

            return self::error(500, 'server_error', 'Une erreur inattendue est survenue. Réessayez dans un instant.');
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function error(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message, ...$extra], $status, $headers);
    }

    private static function httpError(HttpExceptionInterface $exception): JsonResponse
    {
        [$code, $message] = match ($exception->getStatusCode()) {
            403 => ['forbidden', 'Cette action n\'est pas autorisée.'],
            404 => ['not_found', 'Cette adresse n\'existe pas.'],
            405 => ['method_not_allowed', 'Cette méthode n\'est pas acceptée ici.'],
            // Jeton CSRF absent ou périmé : le front doit rappeler
            // /sanctum/csrf-cookie.
            419 => ['session_expired', 'La page a expiré. Rechargez-la puis réessayez.'],
            503 => ['unavailable', 'Le service est momentanément indisponible.'],
            default => ['http_error', 'La requête n\'a pas pu aboutir.'],
        };

        return self::error($exception->getStatusCode(), $code, $message, headers: $exception->getHeaders());
    }

    private static function wantsJson(Request $request): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        return ! in_array('web', $request->route()?->gatherMiddleware() ?? [], true);
    }
}
