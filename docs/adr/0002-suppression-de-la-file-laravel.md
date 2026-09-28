# ADR 0002 — Suppression de la file Laravel

- Statut : accepté
- Date : 2026-09-27

## Contexte

La référence prévoyait deux usages d'une file Laravel (`queue:work`) :

1. un job `PurgeDemoAccount` différé jusqu'à l'expiration de chaque compte ;
2. un job `EmitBurst` sur la file `demo-emit`, déclenché par `POST /demo/burst`.

Un worker `queue:work` sur `maintenance,demo-emit` est une charge permanente
supplémentaire, qui n'entre pas dans le budget mémoire du nœud (8 Go, 4 600 Mo alloués
aux charges). Le verrou de rafale posait en outre un problème de fond : pris par la
requête HTTP et libéré par le job, il pouvait expirer pendant que le job attendait dans
la file, laissant deux rafales se chevaucher.

## Décision

Aucune file Laravel. `QUEUE_CONNECTION=sync`, aucun `queue:work` déployé.

- **Purge.** Le middleware `demo.alive` détecte un compte expiré dès la seconde de son
  expiration et le purge dans la même requête, avant de répondre 401 `demo_expired`.
  Le front appelle `/me` quand son compte à rebours atteint zéro : l'écran « Session
  terminée » décrit une suppression déjà faite. Un balayage toutes les 5 minutes ne
  rattrape que les visiteurs partis avant : d'abord un CronJob, puis la boucle du
  générateur ([ADR 0006](0006-purge-dans-le-generateur.md)). `DELETE /me` purge en
  synchrone.
- **Rafale.** `POST /demo/burst` pose `SET demo:burst {ends_at} NX EX {durée}`. Si la
  clé existe, 409 `burst_running` avec `Retry-After` égal à son TTL. Le générateur
  `demo:emit-events` (un Deployment à un réplica) lit la clé à chaque tick : débit de
  fond tant qu'elle est absente, `DEMO_BURST_RATE` tant qu'elle existe.

## Conséquences

- Le verrou est atomique et ne dépend d'aucun intermédiaire : il ne peut ni expirer
  avant la rafale ni lui survivre, puisqu'il EST la rafale.
- Suppression immédiate si le visiteur est présent à l'expiration ; au plus tard
  5 minutes après l'expiration s'il est parti (délai du balayage). Entre les deux, le
  compte est inutilisable.
- Deux requêtes simultanées peuvent purger le même compte. La purge est idempotente :
  la seconde ne supprime rien, ne journalise rien et répond le même 401.
- Le générateur remplace le worker de file dans le budget : c'est une charge à ajouter
  (cf. DEPLOY.md).
- Réintroduire une file demanderait de déployer `queue:work` et de l'inscrire au budget
  mémoire. `.env.example` le rappelle à côté de `QUEUE_CONNECTION`.
