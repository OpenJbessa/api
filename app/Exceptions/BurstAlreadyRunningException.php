<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Une rafale est déjà en cours : une seule à la fois pour tout le site.
 *
 * Rendue en 409 burst_running (bootstrap/app.php), avec Retry-After égal au
 * temps restant de la rafale en cours.
 */
class BurstAlreadyRunningException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Une rafale est déjà en cours.');
    }
}
