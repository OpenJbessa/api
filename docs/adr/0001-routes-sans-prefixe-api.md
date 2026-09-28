# ADR 0001 — Routes sans préfixe `/api`

- Statut : accepté
- Date : 2026-09-27

## Contexte

Le cahier des charges décrit le contrat de la démo sous `/api` : `POST /api/auth/demo`,
`GET /api/me`, etc. L'application, elle, est déjà servie seule sur `api.jbessa.tech`,
avec `apiPrefix: ''` : les endpoints de plateforme (`/status`, `/infrastructure`,
`/scaling`) vivent à la racine, et le front comme la documentation les appellent ainsi.

## Décision

Pas de préfixe. Le contrat devient :

| Cahier des charges        | Retenu                 |
| ------------------------- | ---------------------- |
| `POST /api/auth/demo`     | `POST /auth/demo`      |
| `GET /api/me`             | `GET /me`              |
| `DELETE /api/me`          | `DELETE /me`           |
| `POST /api/auth/logout`   | `POST /auth/logout`    |
| `POST /api/access/elevate`| `POST /access/elevate` |
| `POST /api/demo/burst`    | `POST /demo/burst`     |
| `GET /api/admin/overview` | `GET /admin/overview`  |

Les routes OAuth (`/auth/{github,google}/redirect` et `/callback`) restent dans
`routes/web.php`, sans changement.

## Conséquences

- Le sous-domaine joue déjà le rôle du préfixe : `/api` sur `api.jbessa.tech` serait
  redondant, et changerait l'URL des endpoints de plateforme déjà consommés.
- Aucune collision : `/auth/demo` et `/auth/logout` n'ont que deux segments, les routes
  OAuth en ont trois et contraignent `{provider}` à `github|google`.
- Laravel ne reconnaît plus les requêtes d'API à leur chemin (`api/*`). Le rendu JSON des
  erreurs se décide donc sur le groupe de middleware : toute route hors du groupe `web`
  répond en JSON (`App\Exceptions\ErrorResponses`).
- `config/cors.php` liste les chemins un par un, puisque le motif `api/*` par défaut ne
  couvre rien.
