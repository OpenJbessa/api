# ADR 0005 — PHP 8.5, Laravel 13 et Pest

- Statut : accepté
- Date : 2026-09-27

## Contexte

Le cahier des charges fixe PHP 8.3, Laravel 12 et PHPUnit. Le dépôt existant est en
Laravel 13, avec Pest, et son `composer.lock` exige PHP ≥ 8.4.1 (Symfony 8, PHPUnit 12,
Pest 5). Redescendre imposait de changer une grande partie des dépendances.

## Décision

- PHP 8.5, pour les images comme pour `composer.json` (`"php": "^8.5"`) : c'est la
  version du poste de développement et celle que déclarent les consignes du dépôt.
- Laravel 13 et Pest, déjà en place.

## Conséquences

- Image de base `php:8.5-fpm-trixie` (Debian slim). OPcache y est intégré d'office.
- La base de démonstration fournie comme référence, écrite pour Laravel 12 et PHPUnit, a
  été portée : attributs `#[Fillable]`, `#[Scope]`, `#[Signature]`, tests Pest. La
  référence elle-même a été retirée du dépôt une fois le portage terminé.
