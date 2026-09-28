# ADR 0004 — nginx en conteneur annexe du pod de l'API

- Statut : accepté
- Date : 2026-09-28

## Contexte

PHP-FPM parle FastCGI, pas HTTP. Traefik, l'ingress du cluster, ne sait pas joindre un
backend FastCGI. Le gitops actuel décrit un conteneur unique écoutant en HTTP sur 8080.

## Décision

Le pod de l'API a deux conteneurs qui partagent son espace réseau :

- `php-fpm` (image `api`, cible `production`), qui écoute sur `127.0.0.1:9000`
  seulement ;
- `nginx` (image `api-nginx`, cible `nginx` du même Dockerfile, sur la base
  `nginxinc/nginx-unprivileged` Alpine), qui écoute sur `8080` et traduit HTTP en FastCGI.

Sa configuration est figée dans l'image plutôt que montée depuis un ConfigMap : elle
évolue avec le code (routes, refus de `/metrics`), et la même configuration sert en local
(`docker-compose.yml`) et en production.

nginx porte aussi deux protections :

- `/metrics` répond 404 à toute requête qui porte `X-Forwarded-For`, donc passée par
  Traefik, quelle que soit la règle de l'IngressRoute ;
- son journal d'accès est du JSON sur stderr, sans adresse IP ni chaîne de requête (le
  retour OAuth y transporte `code` et `state`), sans les sondes ni la collecte.

## Conséquences

- Un second conteneur dans le budget du pod (320 Mo de requête, 480 Mo de limite pour le
  pod entier) : environ 3 Mo mesurés, un seul worker nginx.
- Deux images à construire, signer et référencer ensemble. Leurs tags doivent avancer
  d'un même pas.
- nginx n'a pas besoin des fichiers de l'application : tout part vers `index.php`.
- L'alternative, php-fpm derrière un proxy FastCGI dans Traefik, n'existe pas ; celle
  d'un serveur HTTP en PHP (Octane, FrankenPHP) est exclue par le cahier des charges.
