<?php

namespace App\Exceptions;

use LogicException;

/**
 * Tentative de purge d'un compte qui n'est pas un compte de démonstration.
 *
 * C'est une erreur de programmation, jamais un cas prévu : la purge ne doit
 * atteindre que des comptes `is_demo`. Mieux vaut échouer bruyamment que
 * supprimer le compte du propriétaire.
 */
class PermanentAccountException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Refus de purger un compte qui n\'est pas un compte de démonstration.');
    }
}
