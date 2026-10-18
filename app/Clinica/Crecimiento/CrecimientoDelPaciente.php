<?php

namespace App\Clinica\Crecimiento;

use App\Models\Consulta;
use App\Models\PercentilesPaciente;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * **Las mediciones de un niño, de todas sus consultas** (WEB-7.4.3c): lo que dibujan las gráficas y lista la tabla de percentiles. Una
 * fila de `percentiles_paciente` por consulta; la fecha es la de la medición y, si esa fila no la trae, la de su consulta (`consultas.fecha`).
 * Las filas sin ninguna medida (el escritorio crea una en blanco al abrir la pestaña) y las que no tienen fecha de ningún lado no se
 * incluyen: no hay nada que dibujar ni con qué calcular la edad.
 */
class CrecimientoDelPaciente
{
    /**
     * @return Collection<int,object>  `nroconsulta`, `fecha` (`Y-m-d`), `peso`, `talla`, `c_cefalica`, `c_brazo`, `pulso`, `p_a_sistolica`,
     *                                 `p_a_diastolica` e `imc` (el guardado o, si falta, el calculado), de la más antigua a la más nueva
     */
    public function de(string $regMedico, int $numhistoria): Collection
    {
        $fechas = Consulta::query()
            ->where('reg_medico', $regMedico)->where('numhistoria', $numhistoria)
            ->pluck('fecha', 'nroconsulta');

        return PercentilesPaciente::query()
            ->where('reg_medico', $regMedico)->where('hhistoria', $numhistoria)
            ->orderBy('hconsulta')->get()
            ->map(function (PercentilesPaciente $fila) use ($fechas) {
                $fecha = $fila->fecha ?? ($fechas[$fila->hconsulta] ?? null);
                $fecha = $fecha === null ? null : Carbon::parse($fecha)->toDateString();
                $imc = $fila->imc ?? (($calculado = MedidasAntropometricas::imc($fila->peso, $fila->talla)) > 0 ? $calculado : null);

                return (object) [
                    'nroconsulta' => (int) $fila->hconsulta,
                    'fecha' => $fecha,
                    'peso' => $fila->peso,
                    'talla' => $fila->talla,
                    'c_cefalica' => $fila->c_cefalica,
                    'c_brazo' => $fila->c_brazo,
                    'pulso' => $fila->pulso,
                    'p_a_sistolica' => $fila->p_a_sistolica,
                    'p_a_diastolica' => $fila->p_a_diastolica,
                    'imc' => $imc,
                ];
            })
            ->filter(fn ($m) => $m->fecha !== null && collect(['peso', 'talla', 'c_cefalica', 'c_brazo', 'pulso', 'p_a_sistolica', 'p_a_diastolica'])
                    ->contains(fn ($c) => $m->{$c} !== null))
            ->sortBy([['fecha', 'asc'], ['nroconsulta', 'asc']])
            ->values();
    }
}
