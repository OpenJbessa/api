<?php

/*
|--------------------------------------------------------------------------
| Démo à comptes éphémères
|--------------------------------------------------------------------------
|
| Un visiteur obtient un compte de démonstration (anonyme, GitHub ou Google)
| qui vit `ttl_minutes`, puis disparaît avec toutes ses traces. Rien de
| personnel n'est stocké : adresse en @demo.invalid, ni e-mail réel ni jeton
| OAuth, et des scopes OAuth réduits à l'identité publique.
|
| Cycle de vie :
|   - le middleware `demo.alive` refuse un compte expiré dès la seconde où il
|     expire (401 demo_expired), sans attendre la purge ;
|   - le générateur (demo:emit-events) balaie toutes les 5 minutes les
|     comptes expirés que personne n'a revus (ADR 0006) ;
|   - DELETE /me purge immédiatement, en synchrone.
|
| Aucune file Laravel n'intervient : cf. docs/adr/.
|
*/

return [

    // Durée de vie d'un compte, en minutes.
    'ttl_minutes' => (int) env('DEMO_TTL_MINUTES', 30),

    // Comptes actifs simultanés. Au-delà, la création répond 503
    // demo_capacity : tous partagent le PostgreSQL et le Redis d'un nœud de 8 Go.
    'max_active_accounts' => (int) env('DEMO_MAX_ACTIVE', 50),

    // Accès accordés à la création. Valeur : durée en minutes, null pour la
    // durée du compte. Les clés utilisent ':' et non '.', que la notation
    // config() interpréterait comme un niveau d'imbrication.
    'default_grants' => [
        'dashboard:view' => null,
        'events:read' => null,
    ],

    // Accès temporaires demandables à la volée (POST /access/elevate), à la
    // manière d'une demande d'accès Teleport. Valeur : durée en minutes.
    'elevatable' => [
        'load:burst' => 3,
        'admin:read' => 5,
    ],

    // Élévations autorisées sur toute la vie d'un compte.
    'max_elevations' => (int) env('DEMO_MAX_ELEVATIONS', 3),

    // Intervalle du balayage des comptes expirés, dans la boucle du générateur.
    // C'est le délai maximal entre l'expiration d'un compte abandonné et la
    // suppression de ses traces.
    'purge_interval_seconds' => (int) env('DEMO_PURGE_INTERVAL_SECONDS', 300),

    // Créations de comptes par heure et par adresse IP, départs OAuth compris.
    // Plus large que 5 : derrière le NAT d'une entreprise, tout un bureau
    // partage la même adresse.
    'signup_per_hour' => (int) env('DEMO_SIGNUP_PER_HOUR', 10),

    'social_providers' => ['github', 'google'],

    // Cible des redirections après une connexion OAuth : {frontend_url}/demo.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Connexion Redis de l'index des sessions et du verrou de rafale : la
    // base 0, sans préfixe, que le cache (base 1) ne partage pas.
    'redis_connection' => env('DEMO_REDIS_CONNECTION', 'default'),

    /*
    | Rafale d'événements (POST /demo/burst), une seule à la fois pour tout le
    | site. La clé `lock_key` porte la date de fin et expire avec la rafale :
    | le générateur (demo:emit-events) accélère tant qu'elle existe.
    |
    | 150 événements par seconde et non 200 : c'est ce qui garde le retard
    | maximal à moins de la moitié de MAXLEN pendant que KEDA réagit
    | (calcul dans DEPLOY.md).
    */
    'burst' => [
        'rate' => (int) env('DEMO_BURST_RATE', 150),
        'seconds' => (int) env('DEMO_BURST_SECONDS', 60),
        'lock_key' => 'demo:burst',
    ],

    /*
    |--------------------------------------------------------------------------
    | Séquence KEDA : le stream Redis demo:events
    |--------------------------------------------------------------------------
    |
    | Le générateur (demo:emit-events) écrit, les consommateurs
    | (demo:consume-events) lisent dans le consumer group `workers`, et KEDA
    | règle le nombre de consommateurs sur le retard (lag) du group.
    |
    | Les noms de clés sont écrits en dur dans le ScaledObject : REDIS_PREFIX
    | doit rester vide, sinon KEDA lit une clé inexistante sans erreur.
    |
    */
    'stream' => [
        'key' => 'demo:events',
        'group' => 'workers',

        // XADD MAXLEN ~ : borne la mémoire du stream dans Redis (256 Mo pour
        // tout le monde). À ~150 octets par entrée, 10 000 entrées pèsent
        // quelques mégaoctets. Un retard qui dépasserait cette longueur
        // perdrait les plus anciens événements, et Redis ne saurait plus
        // calculer le retard. Le retard maximal d'une rafale reste sous
        // 4 000 (calcul dans DEPLOY.md) : 10 000 laisse un facteur 2,5.
        'max_length' => (int) env('DEMO_STREAM_MAXLEN', 10000),

        // Débit du générateur hors rafale, en événements par seconde : juste
        // de quoi ne pas avoir de courbes plates au repos.
        'background_rate' => (int) env('DEMO_BACKGROUND_RATE', 2),

        // Traitement simulé par événement. À 20 ms, un consommateur traite
        // 50 événements par seconde : il en faut 3 pour suivre une rafale
        // de 150/s, et les 4 du plafond de KEDA la résorbent.
        'work_ms' => (int) env('DEMO_WORK_MS', 20),

        // XREADGROUP COUNT et BLOCK. Le blocage doit rester sous le
        // read_timeout de phpredis (5 s, config/database.php).
        'read_count' => 50,
        'block_ms' => 2000,

        // Entrées d'un consommateur mort (pod tué en plein lot) récupérées au
        // démarrage par XAUTOCLAIM au-delà de cette inactivité.
        'claim_idle_ms' => 60_000,

        // Un consommateur est compté comme actif s'il a interrogé le stream
        // depuis moins de ce délai (champ `idle` de XINFO CONSUMERS).
        'active_idle_ms' => 10_000,

        // Consommateurs sans entrée en attente et muets depuis ce délai :
        // supprimés du group au démarrage, pour que XINFO CONSUMERS ne
        // grossisse pas d'un nom de pod à chaque redémarrage.
        'forget_idle_ms' => 3_600_000,

        // Fichier touché par le consommateur à chaque tour de boucle (au plus
        // BLOCK 2 s + un lot de 1 s) : la sonde de vie du pod échoue s'il a
        // plus de 30 s. Dans /tmp, seul point d'écriture du conteneur.
        'heartbeat_path' => env('DEMO_HEARTBEAT_PATH', '/tmp/heartbeat'),

        'counters' => [
            'emitted' => 'demo:metrics:emitted_total',
            'processed' => 'demo:metrics:processed_total',
        ],
    ],

    /*
    | GET /metrics, lu par vmagent directement sur le pod. En plus de la
    | NetworkPolicy, l'application exige `Authorization: Bearer {token}` : sans
    | jeton configuré, ou avec un mauvais jeton, la route répond 404.
    */
    'metrics' => [
        'token' => env('METRICS_TOKEN'),
    ],

];
