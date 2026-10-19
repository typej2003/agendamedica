<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarIndicadoresIncidentes;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\IndicadoresIncidentes;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): guardar el panel de laboratorio del ítem 5 de la
 * consulta pediátrica.
 *
 * Escribe lo mismo que el escritorio: los **números** en `examenes_paciente_consulta` y el **resumen** compuesto en
 * `consultas.resultadoexamencomp`. La escritura es la Action `App\Actions\Consulta\GuardarIndicadoresIncidentes`:
 * ahí viven el rastro por columna, el last-write-wins y la regla de no crear filas vacías. Acá sólo se valida el
 * HTTP y se cuenta el resultado.
 */
class ConsultaIndicadoresController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarIndicadoresIncidentes $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // Los campos del panel: números, con coma o con punto decimal (el español usa coma) y sin más texto. La
        // fecha es la de la toma de laboratorio.
        $reglas = ['fecha' => ['nullable', 'date']];
        foreach (IndicadoresIncidentes::claves() as $clave) {
            $reglas[$clave] = ['nullable', 'string', 'max:20', 'regex:/^-?\d+([.,]\d+)?$/'];
        }

        $datos = $request->validate($reglas, [
            '*.regex' => 'Escribe un número (puede llevar coma decimal).',
        ]);

        $resultado = $guardar->ejecutar($consulta, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_INDICADORES,
        ]);

        if ($resultado['campos'] === 0 && ! $resultado['texto']) {
            return $destino->with('estado', 'Sin cambios: los indicadores ya estaban así.');
        }

        return $destino->with('estado', 'Indicadores guardados: el resumen quedó en el resultado de exámenes de la consulta.');
    }
}
