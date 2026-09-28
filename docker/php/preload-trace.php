<?php

/*
|--------------------------------------------------------------------------
| Liste des fichiers à précharger, établie à la construction de l'image
|--------------------------------------------------------------------------
|
| Précharger tout le framework à l'aveugle échoue : une classe dont le parent
| manque (les aides de test, qui étendent PHPUnit, absent en --no-dev) est une
| erreur fatale au démarrage de PHP-FPM. On fait donc servir à l'application
| quelques requêtes représentatives, sans base ni Redis joignables, et on
| retient les fichiers du dossier vendor/ dont elle a réellement chargé les
| classes : tous se chargent sans erreur, par construction.
|
| Écrit la liste dans le fichier donné en argument.
|
*/

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__, 2);
$output = $argv[1] ?? throw new InvalidArgumentException('Chemin de sortie manquant.');

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

// Parcours représentatifs : sonde, authentification refusée, erreur de
// validation, 404, dépendances injoignables (rendu des erreurs compris).
$requests = [
    ['GET', '/up'],
    ['GET', '/ready'],
    ['GET', '/me'],
    ['POST', '/auth/demo'],
    ['POST', '/access/elevate'],
    ['GET', '/metrics'],
    ['GET', '/status'],
    ['GET', '/introuvable'],
];

foreach ($requests as [$method, $uri]) {
    $request = Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'application/json']);

    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
    } catch (Throwable) {
        // Seuls les fichiers chargés comptent, pas la réponse.
    }
}

$vendor = realpath($root.'/vendor').'/';

// Seuls les fichiers qui DÉCLARENT une classe, une interface, un trait ou une
// énumération : un fichier inclus pour son effet (la configuration par défaut
// du framework, par exemple) s'exécuterait au préchargement, avant que
// l'application n'existe. Les fichiers de fonctions (helpers) sont déjà chargés
// par l'autoloader de Composer.
$declared = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());

$files = array_values(array_unique(array_filter(
    array_map(fn (string $name): string|false => (new ReflectionClass($name))->getFileName(), $declared),
    // vendor/composer est le chargeur lui-même : il est requis par preload.php.
    fn (string|false $file): bool => $file !== false
        && str_starts_with($file, $vendor)
        && ! str_starts_with($file, $vendor.'composer/'),
)));

file_put_contents($output, '<?php return '.var_export($files, true).";\n");

fwrite(STDERR, sprintf("preload : %d fichiers retenus\n", count($files)));
