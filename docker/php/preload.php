<?php

/*
| Préchargement OPcache (opcache.preload), exécuté une fois par le maître
| PHP-FPM au démarrage. Les fichiers viennent de la liste établie à la
| construction (preload-trace.php) : l'autoloader résout leurs dépendances,
| dans n'importe quel ordre.
*/

require '/var/www/html/vendor/autoload.php';

foreach (require '/opt/app-cache/preload-files.php' as $file) {
    require_once $file;
}
