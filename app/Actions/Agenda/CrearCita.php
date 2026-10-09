<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Alta de una cita en `cola`.
 *
 * La usan las dos superficies: la web (la secretaría desde "Nueva cita") y el sync del móvil
 * (una creación de `cola`), para que la fila nazca igual y con el mismo rastro en `sync_changes`
 * (PLAN-WEB.md, R3). Las reglas de la jornada —qué número le toca, qué turno, en qué bloque cae—
 * **no** viven acá: las resuelve `App\Clinica\Agenda\ArmadorDeAgenda`, que es un solo lugar para
 * las dos superficies.
 *
 * Lo que sí garantiza esta Action es la **invariante de la fila**: una cita siempre es de un
 * paciente (con historia o sin ella) y siempre tiene día y hora — `hora_ini` es obligatoria en el
 * esquema legado, y por eso una sede por orden de llegada la llena con la hora de apertura del
 * bloque en vez de dejarla nula.
 */
final class CrearCita
{
    /**
     * @param  string  $regMedico  El registro dueño de la cita. Manda sobre lo que traiga `$datos`:
     *                             es el tenant, y ninguna superficie lo elige (lo resuelve el
     *                             servidor desde el médico autenticado o el contexto).
     * @param  array<string,mixed>  $datos  Columnas resueltas de `cola`: `fecha`, `hora_ini`,
     *                             `numorden`, `turno`, `medical_center_id`, `motivo`, `monto`,
     *                             `tipo`, `medico` y el paciente por `numhistoria` o por
     *                             `paciente_sinhistoria_id`.
     * @param  Carbon  $cuando  Momento de la creación (el del cliente en el sync).
     * @param  string  $origen  `web` o `mobile`: quién la escribió.
     * @param  int|null  $tempId  El id temporal del cliente, para poder contestarle cuál fila es cuál.
     *
     * @throws InvalidArgumentException si la cita no dice a qué paciente pertenece o le falta el día.
     */
    public function ejecutar(
        string $regMedico,
        array $datos,
        Carbon $cuando,
        string $origen = 'web',
        ?int $tempId = null
    ): Cola {
        if (($datos['numhistoria'] ?? null) === null && ($datos['paciente_sinhistoria_id'] ?? null) === null) {
            throw new InvalidArgumentException('La cita no indica a qué paciente pertenece.');
        }

        if (($datos['fecha'] ?? null) === null) {
            throw new InvalidArgumentException('La cita necesita una fecha.');
        }

        $datos['reg_medico'] = $regMedico;

        $cola = Cola::create($datos);

        RegistroDeCambios::registrarOperacion(
            'cola',
            $cola->id,
            $regMedico,
            'created',
            null,
            null,
            $cuando,
            $origen,
            $tempId,
        );

        return $cola;
    }
}
