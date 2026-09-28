<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * L'adresse IP du visiteur, la même pour tous les limiteurs de débit.
 *
 * Derrière Cloudflare puis Traefik, `$request->ip()` rend l'adresse de Traefik
 * (ou d'un nœud Cloudflare) : tous les visiteurs partageraient le même
 * compteur. L'adresse réelle est dans CF-Connecting-IP.
 *
 * Cet en-tête n'est fiable QUE parce que le pare-feu du VPS n'accepte 80/443
 * que depuis les plages Cloudflare : ailleurs, n'importe quel client pourrait
 * le forger et choisir son compteur.
 */
final class ClientIp
{
    public const HEADER = 'CF-Connecting-IP';

    public static function of(Request $request): string
    {
        $forwarded = trim((string) $request->header(self::HEADER, ''));

        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        return (string) $request->ip();
    }
}
