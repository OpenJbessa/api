# ADR 0006 — La purge des comptes abandonnés tourne dans le générateur

- Statut : accepté
- Date : 2026-09-28

## Contexte

Le cahier des charges prévoit un CronJob `demo:purge-expired` toutes les 5 minutes, en
`concurrencyPolicy: Forbid`. Depuis l'ADR 0002, il ne sert plus qu'aux visiteurs partis
avant l'expiration de leur compte : les autres sont purgés dans leur propre requête.

Chaque exécution crée un pod de 96 Mo de requête. La vérification `budget` de gitops ne
compte pas les Jobs, mais le nœud réserve bien cette mémoire pendant qu'ils tournent : au
pic d'autoscaling, une purge portait la réservation réelle à 4 760 Mo (4 568 mesurés par la
CI, plus le générateur et la purge, 96 Mo chacun), au-delà de
l'enveloppe de 4 600 Mo (DEPLOY.md). Or une charge longue, déjà connectée à Redis et
démarrée, tourne en permanence : le générateur d'événements.

## Décision

Plus de CronJob. Le générateur (`demo:emit-events`) exécute le balayage au démarrage,
puis toutes les `DEMO_PURGE_INTERVAL_SECONDS` (300 par défaut), dans sa boucle d'une
seconde.

- La logique est celle d'`AccountPurger`, portée par `ExpiredAccountSweeper`, que la
  commande `demo:purge-expired` utilise aussi pour une purge manuelle.
- Un verrou Redis (`Cache::lock('demo:purge-expired', 240)`) garantit qu'un seul balayage
  tourne à la fois, qu'il vienne du générateur ou de la commande. Il expire seul si le
  processus qui le tient meurt : c'est l'équivalent de `concurrencyPolicy: Forbid`.
- Une purge en échec est journalisée et retentée au passage suivant ; elle n'arrête jamais
  l'émission. La connexion à PostgreSQL est fermée après chaque balayage, pour ne pas
  garder une connexion inactive cinq minutes.

## Conséquences

- Le pic d'autoscaling perd la réservation transitoire du CronJob (96 Mo). Il reste un
  dépassement de 24 Mo, dû au générateur lui-même : cf. DEPLOY.md.
- Le générateur a besoin de PostgreSQL : sa NetworkPolicy et celle de `data/postgres`
  doivent l'autoriser sur 5432.
- La purge dépend désormais d'un Deployment à un réplica. S'il est arrêté, les comptes
  abandonnés gardent leurs traces jusqu'à son retour ; ils restent inutilisables, puisque
  `demo.alive` les refuse et les purge à la première requête. Un générateur arrêté se voit
  aussi à la pente nulle de `demo_events_emitted_total`.
- Un balayage retarde d'autant le tick d'émission en cours : quelques millisecondes par
  compte, 50 comptes au plus (`DEMO_MAX_ACTIVE`).
- Tests : un compte expiré est purgé au démarrage du générateur, puis au balayage suivant
  une fois l'intervalle écoulé ; deux purges ne tournent jamais ensemble
  (`tests/Feature/Stream/EventEmitterTest.php`).
