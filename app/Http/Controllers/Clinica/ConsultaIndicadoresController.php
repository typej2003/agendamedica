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
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): guardar el panel de laboratorio del ítem 5 de
 * la consulta pediátrica.
 *
 * Como el escritorio, el guardado **compone un texto** y lo deja en `consultas.resultadoexamencomp`; los números
 * no se guardan (ver `IndicadoresIncidentes`). La escritura es la Action
 * `App\Actions\Consulta\GuardarIndicadoresIncidentes`: ahí viven el rastro en `sync_changes` (`source = web`, por
 * columna) y el guard last-write-wins del sync. Acá sólo se valida el HTTP y se cuenta el resultado.
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

        // Los 26 campos del panel: números, con coma o con punto decimal (el español usa coma) y sin más texto.
        // El tope es el mismo de la columna del escritorio, que es un texto largo pero no infinito.
        $reglas = [];
        foreach (IndicadoresIncidentes::claves() as $clave) {
            $reglas[$clave] = ['nullable', 'string', 'max:20', 'regex:/^-?\d+([.,]\d+)?$/'];
        }

        $datos = $request->validate($reglas, [
            '*.regex' => 'Escribe un número (puede llevar coma decimal).',
        ]);

        $guardado = $guardar->ejecutar($consulta, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_INDICADORES,
        ]);

        if (! $guardado) {
            // O no había ningún valor (no se crea un resumen vacío) o hay una edición más nueva del escritorio.
            return $destino->with('error', 'No se guardó: escribe al menos un valor, o hay un cambio más reciente de esta consulta (de otro equipo) que no se pisó.');
        }

        return $destino->with('estado', 'Indicadores guardados: el resumen quedó en el resultado de exámenes de la consulta.');
    }
}
