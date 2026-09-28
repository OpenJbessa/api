# API jbessa.tech

API Laravel du portfolio, servie seule sur `api.jbessa.tech` dans un cluster K3s
mono-nœud (8 Go de RAM, la mémoire est la contrainte principale). Elle sert trois choses :

1. **Plateforme** : `/status`, `/infrastructure`, `/scaling`, l'état public du cluster.
2. **Démo à comptes éphémères** : connexion anonyme, GitHub ou Google ; accès temporaires ;
   compte et traces supprimés après 30 minutes.
3. **Séquence KEDA** : des événements synthétiques dans le stream Redis `demo:events`,
   consommés par des workers que KEDA fait varier de 1 à 4 selon le retard du consumer
   group `workers`.

Stack : PHP 8.5, Laravel 13, PostgreSQL 16, Redis 7 (phpredis), Sanctum en mode SPA,
Socialite, Pest, Pint, Larastan niveau 6. Les écarts au cahier des charges sont décrits
dans [`docs/adr/`](docs/adr/). Le déploiement est décrit dans [`DEPLOY.md`](DEPLOY.md).

## Lancement local

Tout passe par `docker-compose.yml` (PostgreSQL 16, Redis 7, PHP-FPM, nginx) : aucune
dépendance sur le poste en dehors de Docker.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

L'API répond sur <http://localhost:8000> (variable `APP_PORT` pour changer de port).

| Tâche                   | Commande                                                   |
| ----------------------- | ---------------------------------------------------------- |
| Tests (Pest)            | `docker compose exec app php artisan test --compact`       |
| Style (Pint)            | `docker compose exec app composer lint`                    |
| Analyse (Larastan 6)    | `docker compose exec app composer analyse`                 |
| Parcours réel (smoke)   | `scripts/smoke.sh` (depuis l'hôte, à travers nginx)        |
| Séquence KEDA en local  | `docker compose --profile stream up -d --scale consumer=4` |

Les tests tournent sur PostgreSQL et Redis réels, avec sessions et cache en Redis comme en
production. Ils utilisent une base (`app_test`) et des index Redis (2 et 3) réservés,
vidés à chaque test ; `tests/TestCase.php` refuse de vider tout autre index.

Les tests désactivent la vérification CSRF. `scripts/smoke.sh` rejoue le parcours d'un
vrai navigateur à travers nginx (cookie CSRF, création, profil, suppression, 401) : c'est
la seule preuve que ce parcours fonctionne. Variables : `BASE_URL`, `ORIGIN`.

### Image de production

```bash
docker build --target production -t api .     # PHP-FPM : API, consommateur, générateur, migrations
docker build --target nginx      -t api-nginx . # conteneur annexe du pod de l'API
```

Système de fichiers en lecture seule, UID 1000, OPcache préchargé, caches figés dans
`/opt/app-cache` ; seul le cache de configuration est écrit au démarrage du pod, par un
initContainer (`php artisan config:cache`, cf. [ADR 0003](docs/adr/0003-chemins-de-cache-et-config-cache.md)).
Les migrations ne tournent jamais au démarrage d'un conteneur : elles passent par un Job
ArgoCD `php artisan migrate --force`, et chacune doit rester compatible avec le code de la
version précédente (on ajoute d'abord, on retire dans une version ultérieure).

## Variables d'environnement

`.env.example` est la référence complète et commentée. En production, elles viennent du
ConfigMap `api-config` et du Secret `api-secrets` du dépôt gitops.

| Variable                          | Rôle                                                           | Défaut local            |
| --------------------------------- | -------------------------------------------------------------- | ----------------------- |
| `APP_KEY` 🔒                      | Chiffrement des cookies                                        | généré par `key:generate` |
| `APP_URL`                         | URL de l'API                                                   | `http://localhost:8000` |
| `FRONTEND_URL`                    | Cible des redirections OAuth (`{FRONTEND_URL}/demo`)           | `http://localhost:3000` |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` 🔒 | PostgreSQL                             | service `postgres`      |
| `REDIS_HOST`, `REDIS_PASSWORD` 🔒 | Redis (base 0 : sessions, stream ; base 1 : cache)             | service `redis`         |
| `REDIS_PREFIX`                    | **Vide** : KEDA lit `demo:events` écrit en dur                 | vide                    |
| `SESSION_DOMAIN`                  | `.jbessa.tech` en production, cookie partagé avec le front     | vide                    |
| `SANCTUM_STATEFUL_DOMAINS`        | Domaines du front authentifiés par cookie                      | `localhost:3000`        |
| `CORS_ALLOWED_ORIGINS`            | Origines autorisées (liste, jamais `*`)                        | `http://localhost:3000` |
| `QUEUE_CONNECTION`                | `sync` : aucune file Laravel ([ADR 0002](docs/adr/0002-suppression-de-la-file-laravel.md)) | `sync` |
| `DEMO_TTL_MINUTES`                | Durée de vie d'un compte                                       | `30`                    |
| `DEMO_MAX_ACTIVE`                 | Comptes simultanés avant 503 `demo_capacity`                   | `50`                    |
| `DEMO_MAX_ELEVATIONS`             | Élévations d'accès par compte                                  | `3`                     |
| `DEMO_SIGNUP_PER_HOUR`            | Créations par heure et par IP (`CF-Connecting-IP`)             | `10`                    |
| `DEMO_PURGE_INTERVAL_SECONDS`     | Balayage des comptes expirés, dans le générateur               | `300`                   |
| `GITHUB_*`, `GOOGLE_*` 🔒         | Applications OAuth                                             | vides                   |
| `DEMO_BACKGROUND_RATE`            | Débit de fond du générateur (événements/s)                     | `2`                     |
| `DEMO_BURST_RATE`, `DEMO_BURST_SECONDS` | Rafale : débit et durée                                  | `150`, `60`             |
| `DEMO_STREAM_MAXLEN`              | Longueur approximative maximale du stream                      | `10000`                 |
| `DEMO_WORK_MS`                    | Traitement simulé par événement                                | `20`                    |
| `METRICS_TOKEN` 🔒                | Jeton de `GET /metrics` (`Authorization: Bearer`)              | vide (404)              |
| `PHP_FPM_MAX_CHILDREN`            | Workers PHP-FPM (`pm = static`)                                | `2`                     |

🔒 : secret en production.

## Contrat d'API

Toutes les dates sont en ISO 8601 (`2026-09-27T12:30:00+00:00`). Pas de préfixe `/api`
([ADR 0001](docs/adr/0001-routes-sans-prefixe-api.md)).

### Démo à comptes éphémères

Authentification par cookie de session (Sanctum, mode SPA). Le front appelle
`GET /sanctum/csrf-cookie`, puis renvoie le cookie `XSRF-TOKEN` dans l'en-tête
`X-XSRF-TOKEN`, avec `credentials: 'include'`.

| Méthode et route                     | Rôle                                            | Réponse                          |
| ------------------------------------ | ----------------------------------------------- | -------------------------------- |
| `POST /auth/demo`                    | Compte anonyme et ouverture de session          | 201 + profil                     |
| `GET /auth/{github,google}/redirect` | Départ vers le fournisseur (navigation)         | 302                              |
| `GET /auth/{github,google}/callback` | Retour OAuth (navigation)                       | 302 vers `{FRONTEND_URL}/demo`, `/demo?erreur=social` ou `/demo?erreur=capacite` |
| `GET /me`                            | Profil                                          | 200 + profil                     |
| `POST /access/elevate`               | Accès temporaire `{ "ability": "load:burst" }`  | 201 `{ ability, expires_at }`    |
| `POST /demo/burst`                   | Rafale, accès `load:burst` requis               | 202 `{ rate, seconds, ends_at }` |
| `GET /admin/overview`                | Chiffres agrégés, accès `admin:read` requis     | 200                              |
| `DELETE /me`                         | Suppression immédiate du compte et des traces   | 204                              |
| `POST /auth/logout`                  | Fermeture de session, sans suppression          | 204                              |

Profil :

```json
{
  "name": "Visiteur CT2J",
  "avatar_url": null,
  "is_demo": true,
  "expires_at": "2026-09-27T12:30:00+00:00",
  "seconds_remaining": 1800,
  "server_time": "2026-09-27T12:00:00+00:00",
  "providers": ["github"],
  "grants": [{ "ability": "dashboard:view", "granted_via": "default", "expires_at": "2026-09-27T12:30:00+00:00" }],
  "elevatable": { "load:burst": 3, "admin:read": 5 },
  "elevations_remaining": 3
}
```

`server_time` sert à corriger la dérive de l'horloge du navigateur. Les réponses
authentifiées portent aussi `X-Account-Expires-At`, et celles d'une route protégée par un
accès temporaire, `X-Grant-Expires-At`.

Un compte expiré est refusé (401 `demo_expired`) et purgé dans la même requête : quand son
compte à rebours atteint zéro, le front appelle `/me`, et l'écran « Session terminée »
décrit une suppression déjà faite. Un visiteur parti est purgé au plus tard 5 minutes
après l'expiration, par le balayage que le générateur exécute dans sa boucle
([ADR 0006](docs/adr/0006-purge-dans-le-generateur.md)). `php artisan demo:purge-expired`
lance le même balayage à la main ; un verrou Redis empêche deux purges simultanées.

Rien de personnel n'est stocké : adresse en `@demo.invalid`, ni e-mail réel ni jeton OAuth,
scopes réduits à l'identité publique (GitHub : aucun scope ; Google : `openid profile`).

### Plateforme

| Méthode et route      | Rôle                                                         |
| --------------------- | ------------------------------------------------------------ |
| `GET /status`         | Services sondés par Gatus, et depuis quand                   |
| `GET /infrastructure` | Nœud, namespaces, charges et pods ; inventaire gitops        |
| `GET /scaling`        | Autoscaling du worker : stream, réplicas, ce que KEDA en voit |

Publics, en lecture seule, mis en cache quelques secondes, limités à 60 requêtes par
minute et par IP.

#### `GET /scaling` : rupture de contrat

Le worker consomme désormais le stream `demo:events` au lieu de la file Laravel
`queues:default`. La section `queue` disparaît au profit de `stream`.

| Avant                                  | Maintenant                                   |
| -------------------------------------- | -------------------------------------------- |
| `data.queue.name`                      | `data.stream.group` (`workers`)              |
| `data.queue.key` (`queues:default`)    | `data.stream.key` (`demo:events`)            |
| `data.queue.pending` (jobs en attente) | `data.stream.lag` (entrées non distribuées : la valeur que suit KEDA) |
| `data.queue.delayed`                   | *(supprimé)*                                 |
| `data.queue.reserved`                  | `data.stream.pending` (distribuées, non acquittées) |
| —                                      | `data.stream.length` (entrées dans le stream) |
| —                                      | `data.stream.active_consumers` (workers vivants, vus de Redis) |
| `data.queue.trigger_length`            | `data.stream.lag_count` (retard par réplica) |
| `data.queue.activation_length`         | *(supprimé : sans effet avec 1 réplica minimum)* |
| `data.queue.scaling_up`                | `data.stream.scaling_up` (KEDA demande plus que le minimum) |
| `data.keda.observed_list_length`       | `data.keda.observed_lag`                     |
| `data.keda.watched_key`                | `data.keda.watched_stream`                   |
| —                                      | `data.worker.scale_down_stabilization_seconds` |

`data.worker` garde sa forme (`replicas`, `at_ceiling`, `projected_replicas`…), de même
que `meta` (`sources`, `unavailable_sources`, `collected_at`). `data.stream.lag` et
`data.worker.projected_replicas` valent `null` tant qu'aucun consumer group n'existe, ou
quand Redis ne sait pas calculer le retard.

```json
{
  "data": {
    "worker": {
      "autoscaler": "keda", "namespace": "apps", "deployment": "worker", "scaled_object": "worker",
      "min_replicas": 1, "max_replicas": 4, "polling_interval_seconds": 5, "cooldown_seconds": 300,
      "scale_down_stabilization_seconds": 120,
      "replicas": { "desired": 3, "ready": 2, "available": 2 },
      "at_ceiling": false, "projected_replicas": 3
    },
    "stream": {
      "key": "demo:events", "group": "workers", "length": 1200, "lag": 1150, "pending": 50,
      "active_consumers": 3, "lag_count": 500, "scaling_up": true
    },
    "keda": { "observed_lag": 1150, "watched_stream": "demo:events", "key_matches": true, "redis_prefix": "" }
  },
  "meta": { "sources": ["redis", "victoriametrics"], "unavailable_sources": [], "collected_at": "2026-09-28T06:40:23+00:00" }
}
```

### Sondes et métriques

| Méthode et route | Rôle                                                                        |
| ---------------- | --------------------------------------------------------------------------- |
| `GET /up`        | Vie : `{"status":"ok"}`, sans aucune dépendance                              |
| `GET /ready`     | Disponibilité : PostgreSQL et Redis, une seconde chacun ; 503 `not_ready` sinon |
| `GET /metrics`   | Texte Prometheus, pour vmagent : `Authorization: Bearer {METRICS_TOKEN}`, 404 sans le bon jeton ou derrière Traefik |

Séries : `demo_events_emitted_total`, `demo_events_processed_total`, `demo_stream_lag`,
`demo_active_consumers`, `demo_active_accounts`. `demo_stream_lag` n'est pas émise quand
Redis ne sait pas la calculer.

## Codes d'erreur

Toutes les erreurs sont du JSON `{ "code", "message" }`. `code` est stable, c'est sur lui
que le front décide ; `message` est en français, fait pour être affiché tel quel.

| Statut | `code`                | Quand                                                     | Compléments                    |
| ------ | --------------------- | --------------------------------------------------------- | ------------------------------ |
| 400    | `origin_not_allowed`  | `POST /auth/demo` hors du front (pas de session)          |                                |
| 401    | `demo_expired`        | Compte de démo expiré (et purgé dans la foulée)           |                                |
| 401    | `unauthenticated`     | Pas de session, ou session détruite                       |                                |
| 403    | `grant_required`      | Accès temporaire manquant                                 | `ability`, `requestable`       |
| 403    | `permanent_account`   | `DELETE /me` sur un compte permanent                      |                                |
| 403    | `forbidden`           | Autre refus                                               |                                |
| 404    | `not_found`           | Route inconnue                                            |                                |
| 405    | `method_not_allowed`  | Méthode non acceptée                                      |                                |
| 409    | `burst_running`       | Une rafale est déjà en cours                              | en-tête `Retry-After`          |
| 419    | `session_expired`     | Jeton CSRF absent ou périmé : rappeler `/sanctum/csrf-cookie` |                            |
| 422    | `validation`          | Requête invalide                                          | `errors` par champ             |
| 429    | `too_many_requests`   | Limite de débit                                           | en-tête `Retry-After`          |
| 500    | `server_error`        | Erreur imprévue (hors mode debug)                         |                                |
| 503    | `demo_capacity`       | Plus de place de démonstration                            | `Retry-After` : prochaine place libérée |
| 503    | `source_unavailable`  | Gatus ou VictoriaMetrics injoignable                      | `source`                       |
| 503    | `not_ready`           | `/ready` : une dépendance ne répond pas                   | `checks`                       |

Dans `grant_required`, `requestable` indique si l'accès se demande par
`POST /access/elevate` ; le nom `elevatable` est réservé à l'objet `{ ability: minutes }`
du profil.
