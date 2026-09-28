<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Toutes les places de démonstration sont occupées.
 *
 * Rendue en 503 demo_capacity (bootstrap/app.php) ; `retryAfterSeconds` est
 * le délai jusqu'à l'expiration du plus ancien compte actif, c'est-à-dire la
 * prochaine place libérée.
 */
class DemoCapacityReachedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Capacité de la démo atteinte.');
    }
}
