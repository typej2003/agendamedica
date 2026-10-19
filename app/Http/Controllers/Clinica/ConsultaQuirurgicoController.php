<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEvaluacionPreoperatoria;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **I.Q.** (cirugía pediátrica, 2026-10-19): guardar la evaluación pre-operatoria de la consulta.
 *
 * Una fila por consulta en `pre_aneste` (clave `historia` + `consulta`). Los topes del formulario son los de las
 * columnas del legado: `tipo` y `emergencia` de un carácter y `resultado` de 200. La escritura es la Action
 * `App\Actions\Consulta\GuardarEvaluacionPreoperatoria`.
 */
class ConsultaQuirurgicoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarEvaluacionPreoperatoria $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $datos = $request->validate([
            'tipo'       => ['nullable', 'string', 'max:1'],
            'resultado'  => ['nullable', 'string', 'max:200'],
            'emergencia' => ['nullable', 'string', 'max:1'],
        ]);

        $guardadas = $guardar->ejecutar($consulta, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_QUIRURGICO,
            'pestana' => 'preoperatoria',
        ]);

        return $guardadas > 0
            ? $destino->with('estado', 'Evaluación pre-operatoria guardada.')
            : $destino->with('estado', 'Sin cambios: la evaluación ya estaba así.');
    }
}
