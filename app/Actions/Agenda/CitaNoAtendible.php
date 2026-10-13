<?php

namespace App\Actions\Agenda;

use LogicException;

/**
 * La cita **no se puede marcar como atendida porque no tiene historia** — y una atención sin número de
 * historia queda sin llave clínica (TODO.md §9: "una cita atendida tiene que tener historia").
 *
 * Es la regla del servidor que WEB-4.8 exige (PLAN-WEB.md §3.13). Se lanza desde `AtenderCita`, que es
 * el único lugar por donde pasan las dos superficies que marcan `atendido` (el mostrador de la web y el
 * sync del móvil), así que la regla se escribe una sola vez.
 *
 * **Por qué una excepción propia y no un `InvalidArgumentException`**: el camino del móvil necesita
 * distinguir "esta edición no se aplica" de "este lote está mal" —un cambio que viola una regla de
 * negocio se **descarta** y el teléfono se corrige solo en la sincronización siguiente, pero un error de
 * formato o de permisos es otra cosa—. El nombre dice la regla, no el mecanismo, y cada superficie
 * traduce el rechazo a lo suyo: la web, a un aviso; el sync, a un cambio descartado.
 *
 * Es `LogicException` (no `RuntimeException`) a propósito: no es un fallo del entorno ni de la base, es
 * un estado de los datos que la regla prohíbe. Un `catch (Throwable)` genérico —el que `CambiosEscritorio`
 * usa para no frenar el resto del lote— la sigue atrapando.
 */
class CitaNoAtendible extends LogicException
{
    public static function sinHistoria(): self
    {
        return new self(
            'Esta cita no se puede marcar como atendida porque su paciente todavía no tiene número de '
            . 'historia: asígnaselo antes. Una atención sin historia queda sin número, y el número es la '
            . 'llave de la historia clínica.'
        );
    }
}
