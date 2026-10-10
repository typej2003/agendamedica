<?php

namespace App\Cumpleanos;

use App\Models\Paciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los pacientes **del médico del contexto** que cumplen años el día que se está saludando (WEB-2.9).
 *
 * Es la lectura del saludo de cumpleaños, la misma para la pantalla y para el comando del scheduler: el
 * disparador es la fecha de nacimiento, así que **no hay cita en el medio** —el paciente que cumple
 * años hoy entra aunque no tenga ninguna cita agendada— y lo único que acota es de quién es el
 * paciente: `medico_pacientes` del `reg_medico` del contexto. Un paciente de otro médico no entra
 * nunca, ni siquiera si comparte la fecha.
 *
 * Devuelve `Cumpleano` (el paciente + la fecha que se saluda + si es el día exacto), no filas crudas,
 * para que los ViewModels y el comando no tengan que repetir la regla del 29 de febrero.
 */
class CumpleanosDeHoy
{
    /**
     * @return Collection<int,Cumpleano>
     */
    public function delMedico(string $regMedico, CarbonInterface $dia): Collection
    {
        $filas = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico)
            ->whereNotNull('pacientes.fnacimiento')
            // Día y mes del nacimiento. `substr` funciona igual en MySQL y en SQLite, que es lo que permite
            // que la consulta y su prueba sean la misma; `fnacimiento` es una columna `date`, así que la
            // posición 6 es siempre el mes y no hay formato que adivinar.
            ->whereIn(DB::raw('substr(pacientes.fnacimiento, 6, 5)'), Cumpleanos::diasQueSeSaludan($dia))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->get([
                'pacientes.id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.cedula',
                'pacientes.fnacimiento',
                'pacientes.telefono',
                'pacientes.email',
                'medico_pacientes.numhistoria',
            ]);

        return $filas
            ->map(fn ($fila) => new Cumpleano(
                (new Paciente())->newFromBuilder((array) $fila),
                $dia,
                Cumpleanos::esElDiaExacto($fila->fnacimiento, $dia),
            ))
            ->values();
    }
}
