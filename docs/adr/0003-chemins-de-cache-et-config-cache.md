# ADR 0003 — Chemins de cache hors de `bootstrap/cache`, `config:cache` au démarrage

- Statut : accepté
- Date : 2026-09-28

## Contexte

Le cahier des charges prévoit tous les caches Laravel à la construction de l'image. Deux
obstacles :

1. `config:cache` fige les valeurs de l'environnement présentes au moment où il tourne.
   Exécuté au build, il graverait dans l'image des valeurs de construction (ou des
   secrets), et ignorerait le ConfigMap et le Secret du pod.
2. Le système de fichiers est en lecture seule. Par défaut, les caches de paquets, de
   services, de routes et d'événements vivent dans `bootstrap/cache`, et les vues
   compilées dans `storage/framework/views`. Monter un emptyDir sur ces dossiers masque
   les caches faits au build ; ne rien monter rend le cache de configuration impossible à
   écrire.

## Décision

Laravel lit le chemin de chaque cache dans une variable d'environnement. L'image les
répartit ainsi :

| Cache                   | Variable             | Chemin                          | Écrit            |
| ----------------------- | -------------------- | ------------------------------- | ---------------- |
| Paquets découverts      | `APP_PACKAGES_CACHE` | `/opt/app-cache/packages.php`   | au build         |
| Fournisseurs de services| `APP_SERVICES_CACHE` | `/opt/app-cache/services.php`   | au build         |
| Routes                  | `APP_ROUTES_CACHE`   | `/opt/app-cache/routes.php`     | au build         |
| Événements              | `APP_EVENTS_CACHE`   | `/opt/app-cache/events.php`     | au build         |
| Vues compilées          | `VIEW_COMPILED_PATH` | `/opt/app-cache/views`          | au build         |
| Liste de préchargement  | —                    | `/opt/app-cache/preload-files.php` | au build      |
| Configuration           | `APP_CONFIG_CACHE`   | `/opt/app-config/config.php`    | initContainer    |

`/opt/app-cache` est figé dans l'image, en lecture seule à l'exécution. `/opt/app-config`
est un emptyDir, rempli par un initContainer qui exécute `php artisan config:cache` avec
l'environnement du pod, puis monté en lecture seule dans le conteneur PHP-FPM.

`package:discover` tourne au build et non dans l'initContainer, contrairement à ce que
prévoyait la consigne : son résultat (`packages.php`) ne dépend que de `vendor/`, figé
dans l'image, et son chemin est en lecture seule à l'exécution.

## Conséquences

- `bootstrap/cache` n'a plus besoin d'être inscriptible, ni d'emptyDir.
- Les vues ne sont plus compilées dans `storage/framework/views` : l'initContainer
  `prepare-storage` actuel de gitops (qui crée ces dossiers) devient inutile.
- La route santé intégrée de Laravel rend une vue de paquet, que `view:cache` ne compile
  pas. Elle a été remplacée par une route JSON sans dépendance (`routes/api.php`).
- Sans initContainer (un `docker run` isolé, le consommateur, le générateur), Laravel lit
  simplement la configuration depuis l'environnement : l'absence du cache de configuration
  n'est jamais une panne.
- Le cache de configuration contient les secrets de l'environnement (APP_KEY, mot de passe
  de la base) sur l'emptyDir du nœud. Ils sont déjà dans l'environnement du pod ; l'emptyDir
  est sur disque et non en mémoire, pour ne pas rogner la limite mémoire du pod.
