<?php

namespace App\Exceptions;

use DomainException;

class InvalidSquadException extends DomainException
{
    public function __construct(public readonly array $qualitySummary)
    {
        parent::__construct('SOURCE_SQUAD_INVALID');
    }

    public function publicMessage(): string
    {
        $affected = array_values(array_filter(
            $this->qualitySummary['teams_with_incomplete_squads'],
            fn (array $team) => $team['reason'] === 'invalid',
        ));
        $count = count($affected);
        $names = implode(', ', array_column(array_slice($affected, 0, 5), 'name'));

        return 'El proveedor devolvió una estructura de plantilla inválida para '
            .$count.($count === 1 ? ' equipo' : ' equipos').'. '
            .'No se publicó la importación. Equipos afectados: '.$names
            .($count > 5 ? ' y '.($count - 5).' más' : '').'.';
    }
}
