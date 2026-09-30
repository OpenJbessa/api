# ADR 0007 — Cosign reste en 2.x tant que la policy Kyverno lit le format hérité

- Statut : accepté
- Date : 2026-09-29

## Contexte

La CI applicative signe les deux images (`api`, `api-nginx`) en keyless : Fulcio délivre un
certificat éphémère à partir du jeton OIDC de GitHub Actions, sans qu'aucune clé privée
n'existe nulle part.

Ce qui vérifie cette signature, côté cluster, est la règle `verify-ghcr-openjbessa` de
`platform/kyverno/policies/verify-images.yaml` (dépôt gitops). C'est un `ClusterPolicy`
`kyverno.io/v1` avec `verifyImages` et un attestor `keyless` classique :

```yaml
attestors:
  - count: 1
    entries:
      - keyless:
          subject: "https://github.com/OpenJbessa/*"
          issuer: "https://token.actions.githubusercontent.com"
          rekor:
            url: https://rekor.sigstore.dev
```

Cet attestor cherche la signature là où Cosign 1.x et 2.x la déposent : sous le tag dérivé
`sha256-<digest>.sig`, avec l'entrée de transparence dans **Rekor v1**, à l'adresse
épinglée ci-dessus.

Cosign 3.0 déplace les trois. La signature devient un « bundle Sigstore » v0.3
(`application/vnd.dev.sigstore.bundle.v0.3+json`) publié comme référent OCI 1.1 à côté de
l'image, et la journalisation passe par la configuration de signature servie par TUF,
donc Rekor v2. L'annonce amont le formule comme un choix de valeurs par défaut : ces
drapeaux « seront activés par défaut » en v3, et les notes de la 3.1.1 confirment que le
format bundle « est désormais la sortie par défaut de la signature ».

Constaté sur les binaires, et non déduit des notes de version :

| Drapeau de `cosign sign` | `cosign 2.6.5` | `cosign 3.1.3` |
|---|---|---|
| `--new-bundle-format` | présent, défaut `false` | **absent** |
| `--rekor-url` | présent, défaut `https://rekor.sigstore.dev` | **absent** |
| `--use-signing-config` | présent, défaut `false` | présent, défaut **`true`** |

`--rekor-url` ne disparaît pas au profit de rien : `--use-signing-config=true` fait que
l'adresse du journal vient désormais de la configuration de signature servie par TUF, donc
Rekor v2, et non plus d'un drapeau que l'on pourrait pointer vers Rekor v1.

La 3.x n'expose donc aucun moyen de revenir au format hérité : ce n'est pas un défaut que
l'on inverse, c'est le seul comportement.

## Décision

`COSIGN_VERSION` reste en **2.6.5** dans `.github/workflows/ci.yml`, et une règle Renovate
(`renovate.json`, `allowedVersions: "<3"`) interdit toute proposition de 3.x.

Une étape `cosign verify` conclut la tâche `images` avec exactement les critères de la
policy — même émetteur, même sujet, même Rekor. Elle ne vérifie pas « une signature
existe » mais « la signature que cette CI vient de produire est celle que le cluster
exigera ».

## Conséquences

Ce que coûterait l'oubli, si la CI passait en 3.x sans que la policy change : la règle
porte `required: true`, la policy `validationFailureAction: Enforce` et
`failurePolicy: Fail`. Une image correctement signée par Cosign 3 apparaîtrait à Kyverno
comme **non signée**, et tous les pods de `ghcr.io/openjbessa/*` seraient refusés à
l'admission — sans qu'aucun manifeste du dépôt gitops n'ait changé, et donc sans que le
diff de la pull request de déploiement laisse rien voir. La panne se découvrirait au
rollout, après merge.

L'étape `cosign verify` de la CI est ce qui déplace cette découverte : elle échoue au
build, sur le dépôt applicatif, avant que quoi que ce soit ne soit proposé à gitops.

Le prix de la décision est de rester sur une branche qui n'est plus la branche courante de
l'amont. Les correctifs de sécurité de Cosign 2.x cesseront d'être publiés avant ceux de
la 3.x ; le jour où ils cessent, la condition de sortie ci-dessous devient bloquante et non
plus optionnelle.

Corollaire à garder en tête : `allowedVersions` borne aussi les pull requests de
remédiation. Si une vulnérabilité de Cosign n'était corrigée qu'en 3.x, Renovate la
signalerait sur le tableau de bord mais ne pourrait pas proposer la montée. C'est le
comportement voulu — passer en 3.x est une décision d'architecture, pas une mise à jour —
mais cela veut dire que l'alerte devra être traitée à la main, en levant la borne, et non
en fusionnant une pull request.

## Condition de sortie

La borne se lève quand **la vérification côté cluster sait lire le nouveau format**, ce
qui suppose les quatre points, dans cet ordre :

1. La policy de gitops gagne un chemin de vérification qui lit les bundles Sigstore v0.3
   publiés en référents OCI 1.1. Kyverno l'expose sous la forme d'un `type: SigstoreBundle`
   dans `verifyImages` — aujourd'hui documenté sur les docs de développement
   (`main.kyverno.io`), pas dans celles de la 1.19 déployée.
2. Ce chemin doit couvrir **la signature de l'image**, et pas seulement les attestations :
   l'exemple amont l'emploie sous `attestations:`.
3. Le `rekor.url` épinglé dans la policy doit suivre vers Rekor v2, puisque c'est là que
   Cosign 3 journalise.
4. Les régressions du genre de kyverno#17363 doivent être purgées — sur Kyverno v1.19.0,
   une signature n'existant que comme référent bundle v0.3 échouait avec
   « accepted signatures do not match threshold, Found: 0, Expected 1 », alors qu'elle
   passait en v1.18.1.

Et la preuve avant de toucher à l'épinglage : `kyverno test platform/kyverno/tests` vert
dans gitops sur une image réellement signée par Cosign 3, puis seulement ensuite la montée
de `COSIGN_VERSION` ici. L'ordre compte — l'inverse laisse une fenêtre où le cluster refuse
tout.

## Sources

- [Cosign v3 is now available](https://blog.sigstore.dev/cosign-3-0-available/) — Sigstore,
  les drapeaux `--new-bundle-format`, `--trusted-root` et `--use-signing-config` activés
  par défaut en v3.
- [Notes de version cosign v3.1.1](https://github.com/sigstore/cosign/releases/tag/v3.1.1)
  — le format bundle comme sortie par défaut de la signature, journalisation Rekor v2.
- [kyverno#17363](https://github.com/kyverno/kyverno/issues/17363) — `[Bug]`
  `ImageValidatingPolicy` : la vérification échoue sur Kyverno v1.19.0 pour des signatures
  n'existant que comme référents bundle Sigstore v0.3 ; fonctionnait en v1.18.1. Ouvert.
- [Kyverno — Sigstore (docs de développement)](https://main.kyverno.io/docs/policy-types/cluster-policy/verify-images/sigstore/)
  — « Container images signatures that use sigstore bundle format such as GitHub Artifact
  Attestation can be verified using verification type `SigstoreBundle`. »
- `cosign sign --help` en v2.6.5 et en v3.1.3, pour le tableau des valeurs par défaut
  ci-dessus.
