<?php

namespace App\Clinica\Impresion;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Office;
use App\Support\FechaClinica;
use Carbon\Carbon;

/**
 * **La próxima cita que se imprime al pie del récipe** (WEB-5.2): la primera cita **ya agendada** del paciente con
 * ese médico, de **mañana en adelante**.
 *
 * ## La regla, contra el escritorio y contra el móvil
 *
 * El escritorio la saca con `d_fecha_cita` (`utilitarios/gfimprimirindicacion.srf` →
 * `utilitarios/d_fecha_cita.srd`): `cola.numhistoria = :historia AND cola.fecha > today()`, ordenada por fecha, y
 * toma la primera fila. Es **estrictamente posterior a hoy**: la cita de hoy —la que se está atendiendo— no
 * cuenta. La web hace lo mismo.
 *
 * El móvil (`RecipesRepository.proximaCita`) usa `fecha >= hoy`, así que **imprime la cita de hoy como si fuera la
 * próxima** (la del paciente que justamente se está atendiendo). Es una diferencia deliberada: la fuente manda.
 *
 * Dos precisiones propias de la web:
 *
 *  - Se acota al **médico** (`reg_medico` de la consulta): el paciente puede tener citas con otro médico del mismo
 *    consultorio y esas no son "su próxima cita" de este récipe.
 *  - Las citas que el **escritorio movió** a otro día (`movida_escritorio`) no cuentan: son la fila vieja y el
 *    paciente ya está en la fecha nueva (la misma exclusión que `ArmadorDeAgenda::citaPendiente`).
 *  - La **hora** sólo se imprime si la cita es de una sede que agenda **por hora** (`Office::MODALIDAD_HORA`); en
 *    una sede por orden de llegada la hora de la fila es la del bloque y prometerla al paciente sería engañarlo
 *    (el escritorio imprime sólo la fecha).
 *
 * Es de **sólo lectura** y no sabe de HTTP.
 */
final class ProximaCitaDelRecipe
{
    /**
     * @return array{fecha:string, hora:?string}|null  la fecha `AAAA-MM-DD` y la hora `HH:MM` (si corresponde)
     */
    public function de(string $regMedico, ?int $numhistoria, ?int $pacienteId): ?array
    {
        if ($numhistoria === null && $pacienteId === null) {
            return null;
        }

        $cita = Cola::where('reg_medico', $regMedico)
            ->whereDate('fecha', '>', FechaDelConsultorio::hoy()->toDateString())
            ->where(function ($consulta) {
                $consulta->whereNull('movida_escritorio')->orWhere('movida_escritorio', 0);
            })
            ->where(function ($consulta) use ($numhistoria, $pacienteId) {
                if ($numhistoria !== null) {
                    $consulta->orWhere('numhistoria', $numhistoria);
                }
                if ($pacienteId !== null) {
                    $consulta->orWhere('paciente_sinhistoria_id', $pacienteId);
                }
            })
            ->orderBy('fecha')
            ->orderBy('hora_ini')
            ->orderBy('id')
            ->first();

        if ($cita === null) {
            return null;
        }

        return [
            'fecha' => Carbon::parse($cita->fecha)->toDateString(),
            'hora' => $this->horaDe($cita, $regMedico),
        ];
    }

    /**
     * La frase que se imprime y se muestra: `Próxima cita: 15/10/2026` o, si la sede agenda por hora,
     * `Próxima cita: 15/10/2026  10:30`.
     *
     * @param  array{fecha:string, hora:?string}  $cita  lo que devuelve `de()`
     */
    public static function texto(array $cita): string
    {
        return 'Próxima cita: ' . FechaClinica::formato($cita['fecha']) . ($cita['hora'] === null ? '' : '  ' . $cita['hora']);
    }

    private function horaDe(Cola $cita, string $regMedico): ?string
    {
        if ($cita->medical_center_id === null || trim((string) $cita->hora_ini) === '') {
            return null;
        }

        $modalidad = Office::where('medical_center_id', $cita->medical_center_id)
            ->where('reg_medico', $regMedico)
            ->orderBy('id')
            ->value('modalidad');

        return $modalidad === Office::MODALIDAD_HORA ? substr((string) $cita->hora_ini, 0, 5) : null;
    }
}
