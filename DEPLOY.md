# Déploiement de l'API — livrable pour le dépôt gitops

Ce document décrit, charge par charge, ce que le dépôt gitops doit déclarer pour cette
version de l'API : images, commandes, mémoire, volumes, sondes, variables et secrets. Il ne
contient pas les manifestes eux-mêmes, seulement de quoi les écrire.

Toutes les mesures ont été faites le 2026-09-28, sur le poste de développement (Docker sous
WSL2), avec l'image de production en conditions de pod : système de fichiers en lecture
seule, UID 1000, capabilities retirées.

---

## ⚠ Budget mémoire : 24 Mo au-delà de l'enveloppe au pic

**Le générateur d'événements (`demo:emit-events`) est une charge nouvelle, absente du
budget actuel.** Il remplace la file Laravel du cahier des charges, qui n'est pas déployée
([ADR 0002](docs/adr/0002-suppression-de-la-file-laravel.md)), et porte désormais la purge
des comptes abandonnés : il n'y a plus de CronJob
([ADR 0006](docs/adr/0006-purge-dans-le-generateur.md)). Même réduit à 56 Mo, il fait
passer le pic d'autoscaling **24 Mo au-delà de l'enveloppe de 4 600 Mo** : la vérification
`budget` de la CI gitops échouera tant qu'un des leviers ci-dessous n'est pas appliqué.

| Charge                            | Requête          | Limite           | CPU demandé | Mesurée au repos     | Mesurée sous charge | Budget actuel   |
| --------------------------------- | ---------------- | ---------------- | ----------- | -------------------- | ------------------- | --------------- |
| API : pod entier                  | **320 Mo**       | **480 Mo**       | 50m         | 48 Mo                |                     | inclus          |
| · conteneur `php-fpm`             | 288 Mo           | 432 Mo           | 40m         | 46 Mo                |                     |                 |
| · conteneur `nginx`               | 32 Mo            | 48 Mo            | 10m         | 2 Mo                 |                     |                 |
| · initContainer `config-cache`    | 64 Mo            | 128 Mo           | 10m         | 32 Mo (pic)          |                     | absorbé ¹       |
| Consommateur `worker` (1 à 4)     | 128 Mo / réplica | 192 Mo / réplica | 25m         | 32 Mo (pic 37 Mo)    |                     | inclus          |
| **Générateur `emitter`** (1)      | **56 Mo**        | **72 Mo**        | 25m         | 31 Mo                | 41 Mo ²             | **hors budget** |
| Job `api-migrate` (PreSync)       | 256 Mo           | 384 Mo           | 50m         | 33 Mo (pic)          |                     | transitoire ³   |

¹ La réservation d'un pod est `max(somme des conteneurs, plus gros initContainer)` :
64 Mo sous 320 Mo n'ajoutent rien. Le découpage 288 + 32 garde les 320 Mo actuels.

² Mesuré pendant une rafale complète à 150/s (9 034 événements émis), avec l'image de
production : 35,7 à 36,6 Mo pendant la rafale, **pic de 41 Mo pendant la purge de
démarrage de 50 comptes expirés** (le maximum, `DEMO_MAX_ACTIVE`). Requête = pic + 30 %
(53, arrondi à 56) ; limite = requête + 30 % (73, ramené à 72). C'est une mesure locale
de 75 s : elle ne dit rien d'une dérive lente sur plusieurs jours, que MEM-01 et la
limite surveilleront.

³ Réservé une fois par synchronisation. `check-budget.py` ne compte que les Deployment,
StatefulSet et DaemonSet : les Jobs n'y figurent pas.

**Aucune requête existante n'a été baissée.** Les mesures au repos ne disent rien du pic :
les requêtes de l'API, du consommateur et de la migration sont conservées. La colonne
« sous charge » est à remplir après le test de charge externe (critère MEM-01), avant toute
révision. Seule celle du générateur est proposée d'après une mesure sous charge, comme
demandé.

### Effet sur l'enveloppe

Chiffres de `scripts/check-budget.py` (gitops), lancé sur l'état actuel du dépôt, puis
augmentés des changements de ce document :

| Situation                              | Aujourd'hui (CI) | Avec cette version | Écart à 4 600 Mo        |
| -------------------------------------- | ---------------- | ------------------ | ----------------------- |
| Au repos (1 consommateur)              | 4 184 Mo         | 4 240 Mo           | marge 360 Mo            |
| **Au pic (4 consommateurs)**           | 4 568 Mo         | **4 624 Mo**       | **dépasse de 24 Mo**    |
| Synchronisation (migration en cours) ⁴ | 4 440 Mo         | 4 496 Mo           | marge 104 Mo            |
| CPU au pic                             | 1 070m           | 1 095m             | marge 405m sur 1 500m   |

⁴ Hors CI (cf. ³) : la réservation réelle du nœud pendant le PreSync, un consommateur.

La suppression du CronJob a retiré la seule réservation transitoire qui s'ajoutait au pic
(96 Mo, soit 4 748 Mo dans la version précédente de ce document).

**La table du README gitops est en retard sur la CI.** Elle annonce 4 172 / 4 556 Mo ; la
CI mesure 4 184 / 4 568 Mo. L'écart vient de Teleport (ci-dessous). Le commentaire de
`workloads/worker/scaledobject.yaml` (« 3 870 + 384 = 4 254 Mo ») l'est encore plus.

### Détail des dépassements d'ArgoCD et de Teleport, par pod

Obtenu par `helm template` des charts épinglés (argo-cd 10.9.1, teleport-cluster 18.10.0)
avec les valeurs du dépôt, et confirmé par `check-budget.py`.

**ArgoCD : 704 Mo réservés pour une cible de 450 (+254).**

| Pod                               | Requête | Limite | Remarque |
| --------------------------------- | ------- | ------ | -------- |
| `argocd-application-controller`   | 384 Mo  | 768 Mo | l'essentiel du dépassement |
| `argocd-repo-server`              | 192 Mo  | 384 Mo | initContainers `copyutil` 192 Mo et `install-ksops` 32 Mo, absorbés |
| `argocd-server`                   | 64 Mo   | 96 Mo  | |
| `argocd-redis`                    | 64 Mo   | 96 Mo  | |
| `argocd-applicationset-controller`| 0       | —      | 0 réplica |
| Job `argocd-redis-secret-init`    | 16 Mo   | 32 Mo  | transitoire, hors CI |

Le texte du README gitops (« ArgoCD, +62 Mo », contrôleur à 256 Mo de requête et 512 Mo de
limite) décrit une version antérieure : les valeurs actuelles sont 384 / 768. Le même
texte pose le principe « la requête couvre le régime permanent, la limite absorbe le pic
de démarrage » ; revenir à une requête de 256 Mo en gardant la limite à 768 libérerait
**128 Mo**, à valider contre la consommation réelle du contrôleur.

**Teleport : 420 Mo réservés pour une cible de 200 (+220), et non 356.**

| Pod                 | Requête | Limite | Remarque |
| ------------------- | ------- | ------ | -------- |
| `teleport-proxy`    | 256 Mo  | 512 Mo | imposé par l'initContainer `wait-auth-update`, codé en dur dans le chart ; le conteneur principal ne demande que 100 Mo |
| `teleport-auth`     | 100 Mo  | 150 Mo | |
| `teleport-operator` | 64 Mo   | 128 Mo | **absent de la table du README gitops** |

L'opérateur a été activé après coup (commentaire de `platform/teleport/values.yaml` :
« 64 Mo de plus sont absorbables »), sans mise à jour de la table ni du paragraphe « Ce qui
a été évité », qui dit encore qu'il n'est pas déployé. Le patch de l'initContainer du proxy
décrit dans le README gitops (inflation Helm par kustomize, patch JSON) libérerait
**156 Mo**.

### Leviers, à trancher (aucun n'est appliqué ici)

| Levier                                                         | Gain au pic | Pic obtenu | Touche à la démo |
| -------------------------------------------------------------- | ----------- | ---------- | ---------------- |
| Patch de `wait-auth-update` (Teleport proxy) : 256 → 100 Mo    | −156 Mo     | 4 468 Mo   | non |
| Requête du contrôleur ArgoCD : 384 → 256 Mo, limite inchangée  | −128 Mo     | 4 496 Mo   | non |
| Désactiver l'opérateur Teleport (rôles appliqués par `tctl`)   | −64 Mo      | 4 560 Mo   | non |
| Plafonner le consommateur à 3 réplicas                         | −128 Mo     | 4 496 Mo   | oui : le retard ne se résorbe qu'après la rafale |

Chacun des trois premiers suffit seul.

## Temps de reconstruction (critère RECON-02)

Non optimisé dans cette version, mesuré seulement.

| Image       | Taille décompressée | Taille compressée (registre) |
| ----------- | ------------------- | ---------------------------- |
| `api`       | 592 Mo              | 202 Mo (20 couches)          |
| `api-nginx` | 54 Mo               | 23 Mo (11 couches)           |

Téléchargement mesuré depuis le poste de développement : une image comparable (la base
`php:8.5-fpm-trixie` en arm64, 169 Mo compressés, absente du poste) a été tirée de Docker
Hub en **28,2 s, décompression comprise, soit environ 6 Mo/s**. À ce débit, les deux
images de l'API représentent **environ 38 s** d'une reconstruction à froid.

Ce débit est celui de ce poste, pas celui du VPS. À mesurer sur le nœud, sur un cache
d'images vide :

```bash
time sudo k3s crictl pull ghcr.io/openjbessa/api:sha-XXXXXXX
time sudo k3s crictl pull ghcr.io/openjbessa/api-nginx:sha-XXXXXXX
```

L'essentiel du poids vient de l'image de base officielle (`php:8.5-fpm-trixie`), pas de
l'application.

---

## Images et tags

| Image                          | Cible du Dockerfile | Utilisée par                                      |
| ------------------------------ | ------------------- | ------------------------------------------------- |
| `ghcr.io/openjbessa/api`       | `production`        | API (php-fpm), initContainer, consommateur, générateur, Job de migration |
| `ghcr.io/openjbessa/api-nginx` | `nginx`             | conteneur `nginx` du pod de l'API                 |

```bash
docker build --target production -t ghcr.io/openjbessa/api:sha-$GITHUB_SHA .
docker build --target nginx      -t ghcr.io/openjbessa/api-nginx:sha-$GITHUB_SHA .
```

**Les deux tags avancent ensemble, dans toutes les kustomizations**, dans la même pull
request de la CI applicative :

```bash
cd workloads/api
kustomize edit set image \
  ghcr.io/openjbessa/api=ghcr.io/openjbessa/api:sha-$GITHUB_SHA \
  ghcr.io/openjbessa/api-nginx=ghcr.io/openjbessa/api-nginx:sha-$GITHUB_SHA
cd ../worker
kustomize edit set image ghcr.io/openjbessa/api=ghcr.io/openjbessa/api:sha-$GITHUB_SHA
```

Une configuration nginx d'une version et un PHP-FPM d'une autre ne sont pas garantis
compatibles (refus de `/metrics`, format des journaux). Les deux images doivent être
signées par la même CI, pour la politique Kyverno de vérification des signatures.

---

## Contexte de sécurité, commun à tous les conteneurs

Pod Security Admission en `restricted`. Au niveau du pod :

```yaml
securityContext:
  runAsNonRoot: true
  runAsUser: 1000        # nginx : 101, fixé au niveau du conteneur
  runAsGroup: 1000
  fsGroup: 1000          # rend les emptyDir inscriptibles par l'UID 1000
  seccompProfile:
    type: RuntimeDefault
```

Au niveau de chaque conteneur et initContainer :

```yaml
securityContext:
  runAsNonRoot: true
  readOnlyRootFilesystem: true
  allowPrivilegeEscalation: false
  capabilities:
    drop: [ALL]
  seccompProfile:
    type: RuntimeDefault
```

Le conteneur `nginx` précise en plus `runAsUser: 101` et `runAsGroup: 101` (utilisateur de
l'image `nginx-unprivileged`).

**Signal d'arrêt.** L'image `api` déclare `STOPSIGNAL SIGQUIT` (hérité de `php:*-fpm`) :
c'est ce signal que containerd envoie à toutes les charges de cette image, pas SIGTERM.
PHP-FPM termine alors ses requêtes en cours (`process_control_timeout = 20s`) ; le
consommateur et le générateur finissent leur lot et sortent en 0. Vérifié avec l'image de
production. Aucune configuration à ajouter, mais pas de `stopSignal` à forcer non plus.

---

## Variables et secrets

Les mêmes pour toutes les charges : `envFrom` sur le ConfigMap `api-config` et le Secret
`api-secrets`.

### ConfigMap `api-config`

Valeurs existantes conservées : `APP_ENV`, `APP_DEBUG`, `APP_URL`, `DB_CONNECTION`,
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_CLIENT`,
`REDIS_PREFIX: ""`, `SESSION_DRIVER: redis`, `CACHE_STORE: redis`, `BROADCAST_CONNECTION`,
`FILESYSTEM_DISK`, `GATUS_URL`, `VICTORIAMETRICS_URL`, `LOG_CHANNEL: stderr`, `LOG_LEVEL`.

À changer ou à ajouter :

```yaml
QUEUE_CONNECTION: sync            # était redis : aucune file Laravel (ADR 0002)

FRONTEND_URL: https://jbessa.tech
APP_LOCALE: fr
SESSION_DOMAIN: .jbessa.tech      # cookie partagé entre jbessa.tech et api.jbessa.tech
SESSION_SECURE_COOKIE: "true"
SESSION_SAME_SITE: lax
SANCTUM_STATEFUL_DOMAINS: jbessa.tech
CORS_ALLOWED_ORIGINS: https://jbessa.tech

GITHUB_REDIRECT_URI: https://api.jbessa.tech/auth/github/callback
GOOGLE_REDIRECT_URI: https://api.jbessa.tech/auth/google/callback

DEMO_TTL_MINUTES: "30"
DEMO_MAX_ACTIVE: "50"
DEMO_MAX_ELEVATIONS: "3"
DEMO_SIGNUP_PER_HOUR: "10"
DEMO_PURGE_INTERVAL_SECONDS: "300"  # balayage des comptes abandonnés, dans le générateur
DEMO_BACKGROUND_RATE: "2"
DEMO_BURST_RATE: "150"            # et non 200 : cf. « Réaction de KEDA »
DEMO_BURST_SECONDS: "60"
DEMO_STREAM_MAXLEN: "10000"
DEMO_WORK_MS: "20"
```

Le commentaire actuel du ConfigMap sur `REDIS_PREFIX` parle de `queues:default` : la
raison reste la même, mais la clé que KEDA lit est désormais le stream `demo:events`.

`PHP_FPM_MAX_CHILDREN` n'est pas dans le ConfigMap : c'est une variable du conteneur
`php-fpm` seul (cf. plus bas).

### Secret `api-secrets`

| Clé                    | Rôle                                                              |
| ---------------------- | ----------------------------------------------------------------- |
| `APP_KEY`              | existant                                                          |
| `DB_USERNAME`          | existant                                                          |
| `DB_PASSWORD`          | existant, identique à `data/postgres/credentials.enc.yaml`         |
| `GITHUB_CLIENT_ID`     | application OAuth GitHub (scopes : aucun)                         |
| `GITHUB_CLIENT_SECRET` |                                                                   |
| `GOOGLE_CLIENT_ID`     | application OAuth Google (scopes : `openid profile`)              |
| `GOOGLE_CLIENT_SECRET` |                                                                   |
| `METRICS_TOKEN`        | jeton de `GET /metrics` ; **dupliqué** dans `observability` pour vmagent |

`METRICS_TOKEN` suit la même règle que `DB_PASSWORD` : les secrets ne traversent pas les
namespaces, la même valeur est chiffrée deux fois, et les deux copies tournent ensemble.
Sans jeton, `/metrics` répond 404 et la collecte échoue sans autre message.

---

## Charge 1 — API (`workloads/api/deployment.yaml`)

1 réplica, `strategy: Recreate` (inchangé). `terminationGracePeriodSeconds: 30`.

**Supprimer l'initContainer `prepare-storage`** : les vues compilées ne vivent plus dans
`storage/framework/views` ([ADR 0003](docs/adr/0003-chemins-de-cache-et-config-cache.md)).

**Retirer les annotations `prometheus.io/*` du pod** : la collecte par annotation
n'envoie pas le jeton, obtiendrait 404, et ferait basculer la cible en `up == 0`. Elle est
remplacée par un job vmagent dédié (cf. « Collecte des métriques »).

### initContainer `config-cache`

| | |
| --- | --- |
| Image    | `ghcr.io/openjbessa/api` |
| Commande | `php artisan config:cache` |
| Mémoire  | 64 Mo de requête, 128 Mo de limite |
| CPU      | 10m |
| Volumes  | `app-config` sur `/opt/app-config` (écriture), `tmp` sur `/tmp` |
| Env      | `envFrom` `api-config` + `api-secrets` |

Fige la configuration de l'environnement du pod dans `/opt/app-config/config.php`.
`package:discover` n'y figure pas : il tourne à la construction de l'image (ADR 0003).

### Conteneur `php-fpm`

| | |
| --- | --- |
| Image    | `ghcr.io/openjbessa/api` |
| Commande | celle de l'image (`php-fpm`) |
| Port     | aucun exposé : écoute sur `127.0.0.1:9000`, joint par nginx seulement |
| Mémoire  | 288 Mo de requête, 432 Mo de limite |
| CPU      | 40m |
| Volumes  | `app-config` sur `/opt/app-config` (**lecture seule**), `framework` sur `/var/www/html/storage/framework`, `tmp` sur `/tmp` |
| Env      | `envFrom` `api-config` + `api-secrets` ; `PHP_FPM_MAX_CHILDREN: "2"` |

Deux workers `pm = static` : la mémoire du pod est prévisible, c'est le réglage à revoir
d'après MEM-01, pas la limite.

Sondes (elles passent par nginx, sur le port du pod) :

```yaml
startupProbe:
  httpGet: { path: /up, port: 8080 }
  periodSeconds: 2
  failureThreshold: 30
livenessProbe:
  httpGet: { path: /up, port: 8080 }     # sans dépendance
  periodSeconds: 30
  timeoutSeconds: 5
  failureThreshold: 5
readinessProbe:
  httpGet: { path: /ready, port: 8080 }  # PostgreSQL puis Redis, 1 s chacun
  periodSeconds: 10
  timeoutSeconds: 3
  failureThreshold: 3
```

`timeoutSeconds: 3` sur `/ready` : les deux vérifications se suivent, une seconde chacune
au plus (mesuré : 0,97 s avec PostgreSQL gelé, 0,97 s avec Redis gelé).

### Conteneur `nginx`

| | |
| --- | --- |
| Image    | `ghcr.io/openjbessa/api-nginx` |
| Commande | celle de l'image (`nginx -g 'daemon off;'`) |
| Port     | `8080`, nommé `http` (cible du Service, inchangée) |
| Mémoire  | 32 Mo de requête, 48 Mo de limite |
| CPU      | 10m (le pod garde ses 50m actuels : 40 + 10) |
| Volumes  | `nginx-tmp` sur `/tmp` |
| Env      | aucun |
| `securityContext` | en plus du bloc commun : `runAsUser: 101`, `runAsGroup: 101` |

```yaml
livenessProbe:
  tcpSocket: { port: 8080 }
  periodSeconds: 30
readinessProbe:
  tcpSocket: { port: 8080 }
  periodSeconds: 10
```

### Volumes du pod

```yaml
volumes:
  - name: app-config        # cache de configuration, écrit par l'initContainer
    emptyDir: { sizeLimit: 8Mi }
  - name: framework         # storage/framework : rien n'y écrit aujourd'hui, garde-fou
    emptyDir: { sizeLimit: 16Mi }
  - name: tmp
    emptyDir: { sizeLimit: 32Mi }
  - name: nginx-tmp         # pid et fichiers temporaires de nginx
    emptyDir: { sizeLimit: 16Mi }
```

Tous sur disque et non en mémoire (`medium: Memory` serait décompté de la limite du pod).
`bootstrap/cache` n'a plus besoin d'emptyDir.

---

## Charge 2 — Consommateurs du stream (`workloads/worker/`)

Le Deployment `worker` garde son nom, son budget et son pilotage par KEDA ; il consomme
désormais le stream au lieu de la file.

| | |
| --- | --- |
| Image      | `ghcr.io/openjbessa/api` |
| Commande   | `php artisan demo:consume-events` |
| Réplicas   | 1 à 4, pilotés par KEDA (`replicas` absent du manifeste, inchangé) |
| Mémoire    | 128 Mo de requête, 192 Mo de limite par réplica |
| CPU        | 25m (inchangé) |
| Volumes    | `tmp` sur `/tmp` (emptyDir, 16Mi) — le battement de cœur y est écrit |
| Env        | `envFrom` `api-config` + `api-secrets` |
| Grâce      | `terminationGracePeriodSeconds: 30` (un lot dure au plus 1 s, l'attente 2 s) |

- **Pas d'initContainer** : `prepare-storage` disparaît, et `config:cache` n'est pas
  nécessaire (sans cache, Laravel lit l'environnement ; mesuré en lecture seule).
- **Remplacer la `livenessProbe` actuelle.** Elle appelle `pgrep`, absent de l'image (ni
  `ps` ni `pgrep` dans Debian slim) : elle échouerait à chaque passage et redémarrerait le
  worker en boucle. Le consommateur touche `/tmp/heartbeat` à chaque tour de boucle (au
  plus toutes les 3 s : BLOCK 2 s et un lot de 1 s). La sonde échoue si le fichier manque
  ou a plus de 30 s :

  ```yaml
  livenessProbe:
    exec:
      command:
        - sh
        - -c
        - 'f=/tmp/heartbeat; [ -f "$f" ] && [ $(( $(date +%s) - $(stat -c %Y "$f") )) -lt 30 ]'
    initialDelaySeconds: 10   # aucun battement avant le premier tour de boucle
    periodSeconds: 10
    timeoutSeconds: 5
    failureThreshold: 3
  ```

  Vérifiée sur l'image de production en lecture seule : 0 quand le consommateur tourne,
  1 quand le battement a 60 s, 1 quand le fichier manque ; un battement vieilli est
  rafraîchi par la boucle en moins de 3 s. `sh`, `date` et `stat` sont dans l'image. Elle
  distingue un consommateur bloqué (Redis qui ne répond plus sans couper la connexion)
  d'un consommateur qui attend des événements.
- Le nom de consommateur est le nom d'hôte du pod. Au démarrage, il reprend par XAUTOCLAIM
  les entrées laissées en attente depuis plus de 60 s par un pod tué, et oublie les
  consommateurs sans entrée muets depuis une heure.
- `--max-time` n'existe plus : c'était une option de `queue:work`.

### ScaledObject `worker`

Déclencheur `redis-streams` sur le retard (`lagCount`) du group `workers`. `lagCount`
repose sur le champ `lag` de `XINFO GROUPS` (Redis ≥ 7, ici Redis 7) ; il est apparu dans
la série 2.1x de KEDA (2.12 de mémoire, non revérifié dans le changelog). Le chart déployé
est en 2.20.2 (`apps/platform/keda.yaml`), donc bien postérieur. À confirmer sur le
cluster avant la mise en production :

```bash
kubectl -n keda get deploy keda-operator -o jsonpath='{..image}'
kubectl explain scaledobject.spec.triggers   # ou la doc du scaler redis-streams de cette version
```

Si `lagCount` n'est pas reconnu, repli sur `pendingEntriesCount` (entrées distribuées non
acquittées), moins fidèle : il ne voit pas les entrées pas encore distribuées.

```yaml
spec:
  scaleTargetRef:
    name: worker
  minReplicaCount: 1
  maxReplicaCount: 4
  pollingInterval: 5          # et non 15 (ni 30 par défaut)
  cooldownPeriod: 300         # sans effet avec minReplicaCount: 1
  advanced:
    horizontalPodAutoscalerConfig:
      behavior:
        scaleUp:
          stabilizationWindowSeconds: 0
          policies:
            - type: Pods
              value: 4          # de 1 à 4 en une seule étape
              periodSeconds: 5
        scaleDown:
          stabilizationWindowSeconds: 120
          policies:
            - type: Pods
              value: 1
              periodSeconds: 30
  triggers:
    - type: redis-streams
      metadata:
        address: redis.data.svc.cluster.local:6379
        stream: demo:events
        consumerGroup: workers
        lagCount: "500"         # un réplica par tranche de 500 entrées de retard
        databaseIndex: "0"
        enableTLS: "false"
```

Pas de `activationLagCount` : avec `minReplicaCount: 1`, il n'a aucun effet (il ne sert
qu'à sortir de zéro réplica). La politique de montée actuelle (+2 pods par minute) est à
remplacer : elle bloquait à 3 consommateurs pendant toute la rafale.

L'alerte vmalert sur `keda_scaler_metrics_value{scaledObject="worker"} > 500` décrit encore
« des jobs en attente dans `queues:default` ». La métrique est désormais le retard du
stream ; un seuil utile serait l'approche de `MAXLEN`, par exemple `> 5000` pendant 2 min.

### Réaction de KEDA : retard maximal d'une rafale

Pendant une rafale, un consommateur traite 50 événements/s (20 ms chacun). Le retard
croît donc, avec un seul consommateur, de :

```
croissance = DEMO_BURST_RATE − 50 = 150 − 50 = 100 événements/s
```

Il croît jusqu'à ce que les consommateurs supplémentaires soient là. Avec la politique
ci-dessus, l'HPA passe de 1 à 4 en une étape : dès la première évaluation après le début
de la rafale, le retard (≥ 500 au bout de 5 s) demande `ceil(retard / 500)` réplicas, soit
le plafond de 4 au-delà de 1 500 d'avance. Quatre consommateurs traitent 200/s, plus que la
rafale : le retard redescend alors de 50/s, sans attendre la fin.

```
retard maximal ≈ 100/s × (polling KEDA + délai de l'HPA + démarrage d'un pod)
```

| Terme               | Valeur retenue | D'où elle vient |
| ------------------- | -------------- | --------------- |
| Polling KEDA        | 5 s            | `pollingInterval`. Avec 1 réplica minimum, l'HPA interroge KEDA directement : ce terme est compté par prudence. |
| Délai de l'HPA      | 15 s           | période de synchronisation par défaut du contrôleur (`--horizontal-pod-autoscaler-sync-period`), pire cas |
| Démarrage d'un pod  | 10 s           | mesuré : 0,9 s du lancement du conteneur au premier XREADGROUP (image présente, lecture seule). Le reste couvre l'ordonnancement, le bac à sable du pod et l'admission Kyverno, non mesurés. Jamais moins que la mesure. |
| **Total**           | **30 s**       | |

| Scénario                                    | Réaction | Retard maximal | Marge sous MAXLEN 10 000 |
| ------------------------------------------- | -------- | -------------- | ------------------------ |
| Retenu (démarrage de pod 10 s)              | 30 s     | **3 000**      | **× 3,3**                |
| Pod lent (démarrage 20 s)                   | 40 s     | 4 000          | × 2,5                    |
| Démarrage mesuré seul (0,9 s)               | 21 s     | 2 100          | × 4,8                    |
| *Pour mémoire : 200/s, ancienne politique* | *—*      | *≈ 6 000*      | *× 1,7 (insuffisant)*    |

Le facteur 2 exigé tient dans tous les scénarios à 150/s, jusqu'à 50 s de réaction
(retard 5 000). Au-delà de `MAXLEN`, XADD supprimerait des entrées non lues : Redis ne
saurait plus calculer le retard, `demo_stream_lag` disparaîtrait et la courbe du front
avec elle.

Vérifié en local, en rejouant la rafale complète à 150/s avec un consommateur, puis quatre
à t + 30 s : **retard maximal observé 2 949** (prévu 3 000), `demo_stream_lag` émis dans
les 101 échantillons, retard résorbé pendant la rafale et vidé à t ≈ 65 s.

Descente : 120 s de stabilisation, puis un pod toutes les 30 s ; retour à 1 réplica
environ 3 min 30 après que le retard est retombé.

---

## Charge 3 — Générateur d'événements et purge (nouveau, hors budget)

À ajouter dans `workloads/worker/` (même Application ArgoCD, même tag d'image que le
consommateur : c'est la séquence KEDA).

| | |
| --- | --- |
| Nom        | Deployment `emitter`, label `app.kubernetes.io/name: emitter` |
| Image      | `ghcr.io/openjbessa/api` |
| Commande   | `php artisan demo:emit-events` (débit de fond : `DEMO_BACKGROUND_RATE`) |
| Réplicas   | **1, jamais plus**, `strategy: Recreate` : deux générateurs doubleraient le débit |
| Mémoire    | **56 Mo de requête, 72 Mo de limite** (41 Mo mesurés au pic, + 30 %) — **hors budget actuel** |
| CPU        | 25m |
| Volumes    | `tmp` sur `/tmp` (emptyDir, 16Mi) |
| Sondes     | aucune (pas de port) ; un générateur arrêté se voit à la pente nulle de `demo_events_emitted_total` |
| Env        | `envFrom` `api-config` + `api-secrets` |
| Grâce      | `terminationGracePeriodSeconds: 10` (arrêt mesuré : 0,3 s) |

Il lit la clé `demo:burst` à chaque seconde : débit de fond tant qu'elle est absente,
`DEMO_BURST_RATE` tant qu'elle existe. La rafale ne dépend d'aucune file.

**Il porte aussi la purge des comptes abandonnés, à la place du CronJob**
([ADR 0006](docs/adr/0006-purge-dans-le-generateur.md)) : au démarrage, puis toutes les
`DEMO_PURGE_INTERVAL_SECONDS` (300). Un verrou Redis garantit qu'une seule purge tourne à
la fois, y compris face à un `php artisan demo:purge-expired` lancé à la main. Deux
conséquences pour le déploiement :

- **Le générateur a besoin de PostgreSQL** (5432) en plus de Redis : cf. « Réseau ».
- Le délai maximal entre l'expiration d'un compte abandonné et la suppression de ses
  traces est l'intervalle de balayage (5 minutes), tant que le générateur tourne. S'il est
  arrêté, les comptes expirés restent inutilisables (refusés et purgés à leur prochaine
  requête), mais leurs traces attendent son retour.

La limite de 72 Mo est sous le `memory_limit` de PHP (128 Mo) : un dépassement serait un
OOMKill du conteneur plutôt qu'une erreur PHP, ce qui relance le pod — acceptable pour un
générateur, et visible dans `kube_pod_container_status_restarts_total`.

`config/platform.php` de l'API (inventaire déclaré) est à jour : `emitter`, 56/72 Mo.

---

## Charge 4 — Job de migration (`workloads/api/migrate-job.yaml`)

Inchangé dans son principe (hook PreSync, `php artisan migrate --force --no-interaction`,
256 Mo de requête, 384 Mo de limite, labels `name: api` / `component: migrate`).

- Remplacer le volume `framework-storage` par un emptyDir `tmp` sur `/tmp` : la migration
  n'écrit rien dans `storage/framework` (vérifié en lecture seule).
- Proposition : `hook-delete-policy: BeforeHookCreation,HookSucceeded`. Avec
  `HookSucceeded` seul, un Job en échec reste en place pour ses journaux, mais bloque la
  création du suivant sous le même nom.
- **Règle de compatibilité** : toute migration doit fonctionner avec le code de la version
  précédente, qui tourne encore pendant le PreSync. On ajoute d'abord (colonne nullable,
  table nouvelle), on retire dans une version ultérieure. Les trois migrations de cette
  version sont purement additives.

---

## Réseau

### IngressRoute `api`

Exclure `/metrics` de la route publique :

```yaml
match: Host(`api.jbessa.tech`) && !Path(`/metrics`)
```

Deux barrières de plus derrière celle-ci : nginx répond 404 à toute requête `/metrics` qui
porte `X-Forwarded-For` (donc passée par Traefik), et l'application exige le jeton.

### NetworkPolicy `api` (`workloads/api/networkpolicy.yaml`)

S'applique aussi au Job de migration (même label `name: api`).

- **Entrée** : ajouter vmagent sur le port 8080, en plus de Traefik :
  ```yaml
  - from:
      - namespaceSelector:
          matchLabels: { kubernetes.io/metadata.name: observability }
        podSelector:
          matchLabels: { app.kubernetes.io/name: vmagent }   # à confirmer d'après le chart
    ports:
      - { protocol: TCP, port: 8080 }
  ```
- **Sortie Internet 443 : à ouvrir.** Le retour OAuth échange le code contre un jeton
  auprès de `github.com` / `api.github.com` et de `oauth2.googleapis.com` /
  `openidconnect.googleapis.com`. Sans cette règle, la connexion GitHub et Google échoue
  par expiration, et le visiteur revient sur `/demo?erreur=social`. La règle commentée du
  fichier actuel convient telle quelle (0.0.0.0/0 sauf plages privées, TCP 443).
- Sorties existantes conservées : `data` (5432, 6379), `observability` (8080, 8428).

### NetworkPolicy `worker`

Le consommateur ne parle qu'à Redis : retirer le port 5432.

### Générateur

Une NetworkPolicy `emitter` : sortie vers `data` sur **6379 et 5432** (la purge touche
les sessions Redis et les lignes en base), aucune entrée.

### Côté `data`

- `data/redis/networkpolicy.yaml` : ajouter `emitter` à la liste des noms autorisés
  (`api`, `worker`). L'accès de KEDA y est déjà.
- `data/postgres/networkpolicy.yaml` : ajouter `emitter` (purge) ; `worker` peut être
  retiré (plus d'accès à la base).

---

## Collecte des métriques (vmagent)

`GET /metrics` exige `Authorization: Bearer {METRICS_TOKEN}`. La collecte par annotation
n'envoie pas de jeton : un job dédié dans `observability/victoriametrics/agent-values.yaml`.

```yaml
- job_name: api-demo
  scrape_interval: 15s          # la rafale dure 60 s : 30 s ne donnerait que deux points
  authorization:
    type: Bearer
    credentials_file: /etc/vmagent/secrets/api-metrics-token/token
  kubernetes_sd_configs:
    - role: pod
      namespaces:
        names: [apps]
  relabel_configs:
    - source_labels: [__meta_kubernetes_pod_label_app_kubernetes_io_name, __meta_kubernetes_pod_label_app_kubernetes_io_component]
      action: keep
      regex: api;http
    - source_labels: [__meta_kubernetes_pod_container_port_number]
      action: keep
      regex: "8080"
    - target_label: __metrics_path__
      replacement: /metrics
    - source_labels: [__meta_kubernetes_namespace]
      target_label: namespace
    - source_labels: [__meta_kubernetes_pod_name]
      target_label: pod
```

Le fichier du jeton vient d'un Secret `api-metrics-token` (clé `token`) dans le namespace
`observability`, monté dans vmagent (volumes supplémentaires du chart). Même valeur que
`METRICS_TOKEN` dans `api-secrets`.

Séries exposées : `demo_events_emitted_total`, `demo_events_processed_total`,
`demo_stream_lag`, `demo_active_consumers`, `demo_active_accounts`. Le nombre de workers
vient de Redis (`XINFO CONSUMERS`) : aucun droit sur l'API Kubernetes n'est nécessaire.

---

## Liste des changements dans gitops

- [ ] `workloads/api/deployment.yaml` : retirer `prepare-storage` et les annotations
      `prometheus.io/*` ; initContainer `config-cache` ; conteneurs `php-fpm` et `nginx` ;
      sondes `/up` et `/ready` ; volumes `app-config`, `framework`, `tmp`, `nginx-tmp`.
- [ ] `workloads/api/configmap.yaml` : `QUEUE_CONNECTION: sync` et les nouvelles variables.
- [ ] `workloads/api/secrets.enc.yaml` : OAuth GitHub et Google, `METRICS_TOKEN`.
- [ ] `workloads/api/migrate-job.yaml` : volume `tmp`, politique de suppression du hook.
- [ ] `workloads/api/ingressroute.yaml` : `&& !Path(\`/metrics\`)`.
- [ ] `workloads/api/networkpolicy.yaml` : entrée vmagent, sortie 443.
- [ ] `workloads/api/kustomization.yaml` : image `api-nginx` en plus de `api`.
- [ ] `workloads/worker/deployment.yaml` : commande `demo:consume-events`, sans
      initContainer, `livenessProbe` sur `/tmp/heartbeat` à la place de `pgrep`,
      volume `tmp`.
- [ ] `workloads/worker/scaledobject.yaml` : déclencheur `redis-streams`, polling 5 s,
      comportement de l'HPA, commentaire de budget à jour.
- [ ] `workloads/worker/emitter.yaml` + `emitter-networkpolicy.yaml` (nouveaux).
- [ ] `workloads/worker/networkpolicy.yaml` : retirer 5432.
- [ ] `data/redis/networkpolicy.yaml` : autoriser `emitter`.
- [ ] `data/postgres/networkpolicy.yaml` : autoriser `emitter` ; retirer `worker` (facultatif).
- [ ] `observability/victoriametrics/agent-values.yaml` : job `api-demo`, Secret du jeton.
- [ ] Alerte vmalert sur le retard du stream (texte et seuil).
- [ ] Un levier de budget parmi ceux listés en tête — **à trancher avant la mise en
      production** : sans lui, la CI `budget` échoue (pic à 4 624 Mo).
- [ ] README gitops : table de budget alignée sur `check-budget.py` (opérateur Teleport,
      contrôleur ArgoCD à 384 Mo, générateur), paragraphe « Ce qui a été évité » (opérateur
      Teleport) ; commentaire de budget du ScaledObject.
- [ ] `config/platform.php` de l'API, si un levier change ArgoCD ou Teleport : il recopie
      ces réservations (Teleport y est désormais à 420 Mo).

## Vérification après déploiement

```bash
# Parcours réel d'un visiteur, à travers Traefik, nginx et PHP-FPM
BASE_URL=https://api.jbessa.tech ORIGIN=https://jbessa.tech scripts/smoke.sh

# /metrics n'est pas public
curl -s -o /dev/null -w '%{http_code}\n' https://api.jbessa.tech/metrics   # 404

# Sondes
kubectl -n apps exec deploy/api -c php-fpm -- php -r 'echo file_get_contents("http://127.0.0.1:8080/ready");'
```
