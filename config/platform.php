<?php

/*
|--------------------------------------------------------------------------
| Plateforme
|--------------------------------------------------------------------------
|
| Ce fichier décrit la plateforme K3s décrite par le dépôt gitops et dit où
| aller chercher son état réel. Deux natures de données y cohabitent :
|
|   - les adresses des sources vivantes (Gatus, VictoriaMetrics) ;
|   - l'inventaire DÉCLARÉ, recopié des manifestes gitops.
|
| L'inventaire est une duplication assumée : l'API ne parle pas au kube-apiserver
| et n'a donc aucun moyen de lire les Applications ArgoCD. Toute modification
| d'une vague de synchronisation ou d'un budget mémoire dans gitops doit être
| répercutée ici, sinon /infrastructure annonce un état qui n'est plus celui du
| dépôt. Les valeurs vivantes, elles, viennent toujours du cluster.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Sources vivantes
    |--------------------------------------------------------------------------
    |
    | Les deux vivent dans le namespace `observability`, que la NetworkPolicy de
    | l'API doit autoriser en sortie (workloads/api/networkpolicy.yaml). Sans
    | cette règle l'appel n'échoue pas : il expire, silencieusement.
    |
    */

    'gatus' => [
        'url' => env('GATUS_URL', 'http://gatus.observability.svc.cluster.local:8080'),
        'connect_timeout' => (int) env('GATUS_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('GATUS_TIMEOUT', 5),

        // Fenêtres d'uptime demandées à Gatus, une requête chacune par service.
        // En retirer une allège d'autant la rafale de requêtes.
        'uptime_windows' => ['1h', '24h', '7d'],
    ],

    'metrics' => [
        'url' => env('VICTORIAMETRICS_URL', 'http://vmsingle.observability.svc.cluster.local:8428'),
        'connect_timeout' => (int) env('VICTORIAMETRICS_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('VICTORIAMETRICS_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Durées de cache, en secondes
    |--------------------------------------------------------------------------
    |
    | Calées sur la fréquence réelle des sources : Gatus sonde toutes les 60 s,
    | vmagent collecte toutes les 30 s. Aller plus vite ne renverrait pas des
    | données plus fraîches, seulement plus de charge sur deux pods de 32 et
    | 256 Mo. Redis, lui, répond en direct : la file bouge à la seconde.
    |
    */

    'cache_ttl' => [
        'status' => (int) env('PLATFORM_CACHE_STATUS', 20),
        'infrastructure' => (int) env('PLATFORM_CACHE_INFRASTRUCTURE', 30),
        'scaling' => (int) env('PLATFORM_CACHE_SCALING', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enveloppe mémoire du nœud
    |--------------------------------------------------------------------------
    |
    | Le VPS fait 8 Go, dont 4 600 Mo alloués aux charges — le reste couvre K3s
    | lui-même et la marge d'éviction. C'est la contrainte structurante de toute
    | la plateforme : cf. la table de budget du README gitops.
    |
    */

    'memory_budget_mib' => (int) env('PLATFORM_MEMORY_BUDGET_MIB', 4600),

    /*
    |--------------------------------------------------------------------------
    | Autoscaling du worker
    |--------------------------------------------------------------------------
    |
    | Recopie de workloads/worker/scaledobject.yaml, dans la version que propose
    | DEPLOY.md : le worker consomme le stream demo:events, et KEDA règle son
    | nombre de réplicas sur le retard (lag) du consumer group `workers`. Le
    | plafond de 4 réplicas n'est pas un chiffre rond : il découle du budget
    | mémoire ci-dessus.
    |
    */

    'scaling' => [
        'deployment' => 'worker',
        'namespace' => 'apps',
        'scaled_object' => 'worker',
        'min_replicas' => 1,
        'max_replicas' => 4,
        // Cinq secondes et non les 30 par défaut : une rafale ne dure que 60 s,
        // et chaque seconde de réaction coûte 150 entrées de retard (DEPLOY.md).
        'polling_interval_seconds' => 5,
        'cooldown_seconds' => 300,

        // Avec minReplicaCount à 1, cooldownPeriod ne joue pas : c'est la
        // fenêtre de stabilisation de l'HPA qui fixe la redescente.
        'scale_down_stabilization_seconds' => 120,

        // La clé et le group du stream ne sont pas répétés ici : ils se lisent
        // dans demo.stream, c'est-à-dire exactement ce que lit le consommateur.
        'stream' => [
            // Le nom que KEDA interroge, écrit en dur dans le ScaledObject.
            // L'API le compare à sa propre clé préfixée : s'ils divergent, KEDA
            // lit un stream inexistant, ne voit aucun retard, et le worker reste
            // à un réplica sans la moindre erreur.
            'keda_stream_name' => 'demo:events',

            // Un réplica par tranche de 500 entrées de retard. À 50 événements
            // par seconde et par worker, une rafale de 200/s fait monter le
            // retard de 150/s avec un seul worker : le plafond est atteint en
            // une vingtaine de secondes.
            // Pas de seuil d'activation : avec minReplicaCount à 1, il n'a
            // aucun effet (il ne sert qu'à sortir de zéro réplica).
            'lag_count' => 500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventaire déclaré — miroir des Applications ArgoCD de gitops
    |--------------------------------------------------------------------------
    |
    | `wave` est la sync-wave ArgoCD (apps/**), `memory` reprend les requests et
    | limits des manifestes. Les composants multi-déploiements (ArgoCD, KEDA,
    | cert-manager, Teleport) portent la somme de leurs conteneurs.
    |
    */

    'components' => [
        ['name' => 'namespaces', 'layer' => 'platform', 'namespace' => null, 'wave' => 0, 'role' => 'Namespaces, labels PSA et refus réseau par défaut'],
        ['name' => 'argocd', 'layer' => 'platform', 'namespace' => 'argocd', 'wave' => 0, 'role' => 'Réconciliation GitOps', 'memory' => ['request_mib' => 704, 'limit_mib' => null]],
        ['name' => 'traefik', 'layer' => 'platform', 'namespace' => 'traefik', 'wave' => 1, 'role' => 'Entrée HTTP et terminaison TLS', 'memory' => ['request_mib' => 80, 'limit_mib' => 150]],
        ['name' => 'cert-manager', 'layer' => 'platform', 'namespace' => 'cert-manager', 'wave' => 1, 'role' => 'Émission ACME du certificat wildcard', 'memory' => ['request_mib' => 120, 'limit_mib' => 200]],
        ['name' => 'cert-manager-issuers', 'layer' => 'platform', 'namespace' => 'cert-manager', 'wave' => 2, 'role' => 'ClusterIssuer Let\'s Encrypt et certificats'],
        ['name' => 'keda', 'layer' => 'platform', 'namespace' => 'keda', 'wave' => 3, 'role' => 'Autoscaling du worker sur le retard du stream Redis', 'memory' => ['request_mib' => 120, 'limit_mib' => 200]],
        ['name' => 'kyverno', 'layer' => 'platform', 'namespace' => 'kyverno', 'wave' => 3, 'role' => 'Admission : pods durcis et images signées', 'memory' => ['request_mib' => 176, 'limit_mib' => 264]],
        // Réservation réelle, d'après le rendu du chart (scripts/check-budget.py
        // de gitops) : auth 100 + proxy 256 (imposé par l'initContainer
        // wait-auth-update, codé en dur dans le chart) + opérateur 64. La table
        // du README gitops n'annonce que 356 : l'opérateur y manque. Limite à
        // null : celle de l'initContainer (512) n'est pas réglable par les
        // valeurs du dépôt.
        ['name' => 'teleport', 'layer' => 'platform', 'namespace' => 'teleport', 'wave' => 3, 'role' => 'Accès administrateur audité', 'memory' => ['request_mib' => 420, 'limit_mib' => null]],
        ['name' => 'cnpg-operator', 'layer' => 'data', 'namespace' => 'cnpg-system', 'wave' => 4, 'role' => 'Opérateur CloudNativePG', 'memory' => ['request_mib' => 100, 'limit_mib' => 150]],
        ['name' => 'redis', 'layer' => 'data', 'namespace' => 'data', 'wave' => 4, 'role' => 'Cache, sessions et file de jobs', 'memory' => ['request_mib' => 256, 'limit_mib' => 384]],
        ['name' => 'postgres', 'layer' => 'data', 'namespace' => 'data', 'wave' => 5, 'role' => 'Base applicative', 'memory' => ['request_mib' => 768, 'limit_mib' => 1024]],
        ['name' => 'victoriametrics', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'Stockage des séries temporelles, 15 jours', 'memory' => ['request_mib' => 256, 'limit_mib' => 384]],
        ['name' => 'vmagent', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'Collecte par annotation prometheus.io/scrape', 'memory' => ['request_mib' => 128, 'limit_mib' => 192]],
        ['name' => 'vmalert', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'Règles d\'alerte et Alertmanager vers ntfy', 'memory' => ['request_mib' => 144, 'limit_mib' => 202]],
        ['name' => 'kube-state-metrics', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'État des objets Kubernetes en métriques', 'memory' => ['request_mib' => 48, 'limit_mib' => 96]],
        ['name' => 'grafana', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'Tableau de bord plateforme', 'memory' => ['request_mib' => 128, 'limit_mib' => 256]],
        ['name' => 'gatus', 'layer' => 'observability', 'namespace' => 'observability', 'wave' => 6, 'role' => 'Sondes publiques, source de /status', 'memory' => ['request_mib' => 32, 'limit_mib' => 64]],
        ['name' => 'api', 'layer' => 'workloads', 'namespace' => 'apps', 'wave' => 7, 'role' => 'API Laravel', 'memory' => ['request_mib' => 320, 'limit_mib' => 480]],
        ['name' => 'web', 'layer' => 'workloads', 'namespace' => 'apps', 'wave' => 7, 'role' => 'Front Nuxt', 'memory' => ['request_mib' => 256, 'limit_mib' => 384]],
        ['name' => 'worker', 'layer' => 'workloads', 'namespace' => 'apps', 'wave' => 7, 'role' => 'Consommation du stream demo:events, 1 à 4 réplicas', 'memory' => ['request_mib' => 128, 'limit_mib' => 192]],
        // Nouvelle charge, hors du budget initial (DEPLOY.md) : le générateur
        // remplace la file Laravel, qui n'a jamais été déployée, et porte la
        // purge des comptes abandonnés (ADR 0006). 41 Mo mesurés au pic d'une
        // rafale complète, plus 30 %.
        ['name' => 'emitter', 'layer' => 'workloads', 'namespace' => 'apps', 'wave' => 7, 'role' => 'Générateur d\'événements de la démo KEDA et purge des comptes abandonnés, 1 réplica', 'memory' => ['request_mib' => 56, 'limit_mib' => 72]],
    ],

];
