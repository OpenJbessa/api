<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Une source d'état de la plateforme n'a pas répondu.
 *
 * Le cas le plus courant n'est pas une panne mais une NetworkPolicy : la sortie
 * du pod api vers le namespace `observability` doit être ouverte explicitement
 * (workloads/api/networkpolicy.yaml), faute de quoi l'appel expire sans le
 * moindre message côté cluster.
 */
class PlatformSourceUnavailableException extends Exception
{
    public function __construct(
        public readonly string $source,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @return array{source: string}
     */
    public function context(): array
    {
        return ['source' => $this->source];
    }
}
