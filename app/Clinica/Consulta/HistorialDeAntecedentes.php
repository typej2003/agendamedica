<?php

namespace App\Clinica\Consulta;

use App\Models\AntecedenteMovimiento;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La lectura del registro de movimientos de los antecedentes** de un paciente (`antecedente_movimientos`,
 * 2026-10-20): la línea de tiempo del «Historial» y la fecha «desde» de cada antecedente.
 *
 * La escritura es `App\Actions\Pacientes\RegistrarMovimientoDeAntecedente`. Las filas de `antece_paciente`
 * que no tienen movimientos (las que ya estaban antes del registro o vinieron en la carga inicial) **no
 * tienen fecha**: no se inventa una.
 */
final class HistorialDeAntecedentes
{
    /**
     * Los movimientos de la historia, **del más nuevo al más viejo**, con la consulta (`nroconsulta` y
     * fecha) y el nombre del usuario ya resueltos.
     *
     * @return Collection<int,object>
     */
    public function delPaciente(string $regMedico, ?string $numhistoria): Collection
    {
        if ($numhistoria === null || trim($numhistoria) === '') {
            return collect();
        }

        return DB::table('antecedente_movimientos as m')
            ->leftJoin('consultas as c', 'c.id', '=', 'm.consulta_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.reg_medico', $regMedico)
            ->where('m.numhistoria', (int) $numhistoria)
            ->orderByDesc('m.ocurrido_en')
            ->orderByDesc('m.id')
            ->get([
                'm.id', 'm.codeantecedente', 'm.descripcion', 'm.operacion', 'm.detalles', 'm.alerta',
                'm.consulta_id', 'm.origen', 'm.ocurrido_en',
                'c.nroconsulta as consulta_numero', 'c.fecha as consulta_fecha', 'c.numhistoria as consulta_historia',
                'u.name as usuario',
            ]);
    }

    /**
     * Desde cuándo figura cada antecedente **que el paciente tiene hoy**: la fecha del `agregado` que
     * empezó su presencia actual (si se quitó y se volvió a agregar, cuenta la última vez). Un código sin
     * `agregado` registrado no aparece.
     *
     * @param  Collection<int,object>  $movimientos  los de `delPaciente()`
     * @return array<string,Carbon>  `codeantecedente` => fecha
     */
    public function desdePorCodigo(Collection $movimientos): array
    {
        $desde = [];

        // Del más viejo al más nuevo: un `quitado` borra la fecha y el `agregado` siguiente la vuelve a poner.
        foreach ($movimientos->reverse() as $movimiento) {
            $codigo = trim((string) $movimiento->codeantecedente);

            if ($movimiento->operacion === AntecedenteMovimiento::QUITADO) {
                unset($desde[$codigo]);
            } elseif ($movimiento->operacion === AntecedenteMovimiento::AGREGADO && $movimiento->ocurrido_en !== null) {
                $desde[$codigo] = Carbon::parse($movimiento->ocurrido_en, (string) config('app.timezone', 'UTC'));
            }
        }

        return $desde;
    }
}
