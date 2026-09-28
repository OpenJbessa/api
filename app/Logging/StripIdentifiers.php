<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Retire des journaux tout identifiant de personne ou de session.
 *
 * Le gestionnaire d'exceptions de Laravel ajoute d'office `userId` au contexte
 * de chaque exception journalisée, sans option pour s'en défaire : ce
 * processeur l'enlève à la sortie, comme tout autre champ de la liste.
 */
final class StripIdentifiers implements ProcessorInterface
{
    /**
     * Clés retirées du contexte et des données annexes, à tout niveau.
     */
    private const KEYS = ['userId', 'user_id', 'email', 'session', 'session_id', 'sessionId', 'ip', 'ip_address'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->strip($record->context),
            extra: $this->strip($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->strip($value);
            }
        }

        return $data;
    }
}
