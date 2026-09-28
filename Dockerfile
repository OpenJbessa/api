# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Image de l'API Laravel.
#
# Base Debian slim (l'image officielle php:*-trixie est construite sur
# debian:trixie-slim) et non Alpine : musl pose des problèmes connus avec intl
# et certaines extensions compilées.
#
#   base        — PHP-FPM et ses extensions, commun à tous les étages ;
#   dev         — base + Composer, pour docker-compose.yml (code monté) ;
#   build       — dépendances de production et caches figés ;
#   production  — l'image de l'API, du consommateur, du générateur et des
#                 migrations (même image, commandes différentes) ;
#   nginx       — le conteneur annexe du pod de l'API (Traefik ne parle pas
#                 FastCGI).
#
# PHP 8.5 et non 8.3 : composer.lock exige PHP >= 8.4.1 (Symfony 8, Pest 5).
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.5
ARG PHPREDIS_VERSION=6.3.0

FROM php:${PHP_VERSION}-fpm-trixie AS base

ARG PHPREDIS_VERSION

# Les paquets -dev ne servent qu'à la compilation : on les purge ensuite en ne
# gardant que les bibliothèques partagées réellement liées par les extensions.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libpq-dev; \
    docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pcntl; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | grep -v "^diversion" | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    php -m | grep -qi '^zend opcache$'; \
    php -m | grep -qx 'redis'

# Pool PHP-FPM unique, remplaçant les trois fichiers de l'image officielle.
RUN rm -f /usr/local/etc/php-fpm.d/*.conf
COPY docker/php/php-fpm.d/app.conf /usr/local/etc/php-fpm.d/app.conf
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

# Nombre de workers FPM (pm = static). Chaque worker coûte 30 à 50 Mo : la
# valeur se règle d'après la limite mémoire du pod, pas d'après le trafic.
ENV PHP_FPM_MAX_CHILDREN=2

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Développement local : le code est monté par docker-compose.yml.
# ---------------------------------------------------------------------------
FROM base AS dev

# UID de l'utilisateur hôte, pour que vendor/, storage/ et les fichiers générés
# restent modifiables hors du conteneur.
ARG UID=1000
ARG GID=1000

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*; \
    groupadd --gid "${GID}" app; \
    useradd --uid "${UID}" --gid app --create-home --shell /bin/bash app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

USER app

# ---------------------------------------------------------------------------
# Construction : dépendances de production et caches figés dans l'image.
# ---------------------------------------------------------------------------
FROM base AS build

# unzip sert à Composer, et à cet étage seulement : l'image finale n'en hérite pas.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Construction en root, jetable : les plugins Composer autorisés par
# composer.json (allow-plugins) peuvent s'exécuter.
ENV COMPOSER_ALLOW_SUPERUSER=1

# Chemins des caches hors de bootstrap/cache (docs/adr/0003) : figés ici, lus
# en lecture seule à l'exécution. Seule la configuration est mise en cache au
# démarrage du pod (initContainer), puisqu'elle dépend de l'environnement.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_PACKAGES_CACHE=/opt/app-cache/packages.php \
    APP_SERVICES_CACHE=/opt/app-cache/services.php \
    APP_ROUTES_CACHE=/opt/app-cache/routes.php \
    APP_EVENTS_CACHE=/opt/app-cache/events.php \
    VIEW_COMPILED_PATH=/opt/app-cache/views \
    APP_CONFIG_CACHE=/opt/app-config/config.php

WORKDIR /var/www/html

# Dépendances d'abord : cette couche ne se reconstruit que si le lock change.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN set -eux; \
    composer install --no-dev --no-interaction --no-progress --no-scripts \
        --optimize-autoloader --classmap-authoritative; \
    mkdir -p /opt/app-cache/views; \
    php artisan package:discover; \
    php artisan route:cache; \
    php artisan event:cache; \
    php artisan view:cache; \
    # Trace avec les pilotes de production (PostgreSQL, Redis), injoignables
    # ici : ce sont leurs classes qu'il faut précharger, pas celles des
    # valeurs par défaut du framework.
    DB_CONNECTION=pgsql CACHE_STORE=redis SESSION_DRIVER=redis QUEUE_CONNECTION=sync \
        DB_HOST=127.0.0.1 REDIS_HOST=127.0.0.1 LOG_CHANNEL=null \
        php docker/php/preload-trace.php /opt/app-cache/preload-files.php; \
    rm -rf tests storage/logs/* storage/framework/cache/* storage/framework/sessions/* storage/framework/views/*

# ---------------------------------------------------------------------------
# Production : système de fichiers en lecture seule, utilisateur non root.
#
# Points d'écriture, tous en emptyDir dans le pod : /opt/app-config (le cache
# de configuration écrit par l'initContainer), storage/framework et /tmp.
# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_PACKAGES_CACHE=/opt/app-cache/packages.php \
    APP_SERVICES_CACHE=/opt/app-cache/services.php \
    APP_ROUTES_CACHE=/opt/app-cache/routes.php \
    APP_EVENTS_CACHE=/opt/app-cache/events.php \
    VIEW_COMPILED_PATH=/opt/app-cache/views \
    APP_CONFIG_CACHE=/opt/app-config/config.php

COPY docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-production.ini

# UID et GID fixes, ceux que le Deployment déclare (runAsUser: 1000). Le code
# appartient à root : l'application le lit, elle ne peut pas le modifier.
RUN set -eux; \
    groupadd --gid 1000 app; \
    useradd --uid 1000 --gid app --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin app; \
    mkdir -p /opt/app-config; \
    chown app:app /opt/app-config

COPY --from=build /opt/app-cache /opt/app-cache
COPY --from=build /var/www/html /var/www/html

USER 1000:1000

# Hérité de php:*-fpm, rappelé parce qu'il compte : SIGQUIT est l'arrêt propre
# de PHP-FPM (il termine les requêtes en cours), et c'est le signal que
# containerd enverra à TOUTES les charges de cette image. Le consommateur et le
# générateur l'interceptent aussi (tests/Feature/Stream/ConsumerShutdownTest).
STOPSIGNAL SIGQUIT

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# nginx, conteneur annexe du pod de l'API. Écoute sur 8080, non privilégié ;
# il n'écrit que dans /tmp (emptyDir), sa configuration est figée ici.
# ---------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.28-alpine AS nginx

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# nginx directement, sans les scripts de /docker-entrypoint.d : ils tentent de
# réécrire la configuration (IPv6, gabarits), ce qu'un système de fichiers en
# lecture seule refuse, et écrivent des lignes non JSON sur la sortie.
ENTRYPOINT ["nginx", "-g", "daemon off;"]
CMD []

