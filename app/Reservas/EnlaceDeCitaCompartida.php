<?php

namespace App\Reservas;

use App\Models\Cola;
use Illuminate\Support\Facades\URL;

/**
 * **El link firmado con el que el consultorio comparte una cita** (O-52, 2026-11-02): abre la ficha pública
 * del médico con la **fecha, la hora y el motivo ya elegidos**, para que el paciente sólo confirme.
 *
 * El enlace lleva **firma** (`URL::temporarySignedRoute`) y **vence**: sin eso, cualquiera podría armar una URL
 * con los parámetros que quisiera. Con firma, la ficha los respeta; sin firma o vencida, los ignora y se abre
 * como siempre. No da ningún permiso —reservar sigue necesitando el código y los cupos siguen siendo los de la
 * agenda—, sólo ahorra los pasos.
 *
 * Qué se puede pre-elegir y qué no:
 *
 *  - **Fecha y hora**: de la cita (`cola.fecha` y `cola.hora_ini`). La hora va en `H:i`, que es el formato con el
 *    que el portal nombra sus horarios.
 *  - **Motivo**: `cola.motivo`, y sólo si es un código del catálogo del médico con precio (la ficha lo vuelve a
 *    comprobar: un código ajeno o viejo se descarta).
 *  - **La sede, no**: `cola` guarda `medical_center_id` y el portal identifica cada horario por el **consultorio**
 *    (`offices.id`); no se traduce uno en otro acá para no inventar una equivalencia. Si la hora elegida tiene un
 *    solo consultorio, el paciente no tiene nada que decidir; si tiene varios, los ve.
 *
 * Sin estado y sin base de datos: arma la URL y la devuelve.
 */
final class EnlaceDeCitaCompartida
{
    /** Los días que vale el link. Una cita se comparte con días de anticipación, no con meses. */
    public const DIAS = 7;

    /**
     * El link público de la cita, o `null` si la cita no tiene médico (no hay ficha pública que abrir).
     *
     * @param  int|null  $dias  la vigencia, si se quiere distinta de la de siempre
     */
    public function de(Cola $cita, ?int $dias = null): ?string
    {
        $medicoId = $this->medicoId($cita);

        if ($medicoId === null) {
            return null;
        }

        $parametros = array_filter([
            'medico' => $medicoId,
            'fecha' => $this->fecha($cita),
            'hora' => $this->hora($cita),
            'motivo' => $this->motivo($cita),
        ], fn ($valor) => $valor !== null && $valor !== '');

        return URL::temporarySignedRoute(
            'reservas.medico',
            now()->addDays($dias ?? self::DIAS),
            $parametros,
        );
    }

    /** `Y-m-d` de la cita, o `null` si no tiene fecha usable. */
    public function fecha(Cola $cita): ?string
    {
        $fecha = substr((string) $cita->fecha, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? $fecha : null;
    }

    /** `H:i` de la cita, o `null` si no tiene hora (una sede por orden de llegada no la usa). */
    public function hora(Cola $cita): ?string
    {
        $hora = trim((string) $cita->hora_ini);

        return preg_match('/^\d{1,2}:\d{2}/', $hora) === 1 ? substr($hora, 0, 5) : null;
    }

    /** El motivo de la cita, si es un texto usable. Que exista en el catálogo lo comprueba la ficha pública. */
    public function motivo(Cola $cita): ?string
    {
        $motivo = trim((string) $cita->motivo);

        return $motivo === '' ? null : $motivo;
    }

    /**
     * El médico de la cita: el asignado (`cola.medico_id`) y, si no lo tiene, el del consultorio por la relación
     * legacy. Es la misma resolución que usan la agenda y el link de pago.
     */
    private function medicoId(Cola $cita): ?int
    {
        $asignado = $cita->medico_id;

        if ($asignado !== null && (int) $asignado > 0) {
            return (int) $asignado;
        }

        $medico = $cita->medico()->first();

        return $medico !== null ? (int) $medico->id : null;
    }
}
