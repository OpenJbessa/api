<?php

/*
|--------------------------------------------------------------------------
| Partage des ressources entre origines (CORS)
|--------------------------------------------------------------------------
|
| Le front (jbessa.tech) appelle l'API (api.jbessa.tech) depuis le NAVIGATEUR,
| donc en cross-origin. Laravel n'applique ses en-têtes qu'aux chemins listés
| ci-dessous ; sans eux, l'API répondrait parfaitement et le navigateur
| jetterait la réponse — une panne visible dans la console du navigateur,
| jamais dans les journaux.
|
| Une seule politique pour tous ces chemins, et elle autorise les cookies : la
| démo s'authentifie par le cookie de session (Sanctum, mode SPA). Avec des
| cookies, le navigateur refuse l'origine `*` : les origines sont donc
| explicites, y compris pour les endpoints de plateforme.
|
| Les routes OAuth (/auth/{provider}/redirect et /callback) n'y figurent pas :
| ce sont des navigations, pas des appels fetch.
|
| Le front appelle l'API depuis le navigateur et non depuis son rendu serveur :
| la NetworkPolicy de l'API n'ouvre l'entrée qu'à Traefik
| (workloads/api/networkpolicy.yaml).
|
*/

return [

    'paths' => [
        // Plateforme, publics et en lecture seule.
        'status',
        'infrastructure',
        'scaling',

        // Démo à comptes éphémères.
        'sanctum/csrf-cookie',
        'auth/demo',
        'auth/logout',
        'me',
        'access/elevate',
        'demo/burst',
        'admin/overview',
    ],

    'allowed_methods' => ['GET', 'POST', 'DELETE'],

    'allowed_origins' => [],

    // CORS_ALLOWED_ORIGINS, liste séparée par des virgules (production :
    // https://jbessa.tech), convertie en motifs EXACTS. Déclarées dans
    // `allowed_origins`, une origine unique serait renvoyée à toute requête,
    // même venue d'ailleurs : le navigateur la rejetterait, mais l'en-tête
    // sortirait quand même. Avec des motifs, l'origine reçue est comparée et
    // seule une origine listée obtient une réponse CORS.
    'allowed_origins_patterns' => array_map(
        fn (string $origin): string => '#^'.preg_quote($origin, '#').'$#',
        array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        ))),
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After', 'X-Account-Expires-At', 'X-Grant-Expires-At'],

    // Une heure : la réponse préalable n'a aucune raison d'être rejouée à chaque
    // appel, et le front interroge ces endpoints en boucle.
    'max_age' => 3600,

    // Le cookie de session accompagne les appels (fetch avec
    // credentials: 'include'). SameSite=lax suffit : jbessa.tech et
    // api.jbessa.tech sont le même site.
    'supports_credentials' => true,

];
