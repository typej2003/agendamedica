<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarNeumologiaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Neumología pediátrica** (postgrado, 2026-10-19): guardar los tres informes de la solapa —R.X. de tórax,
 * espirometría y broncoscopía— que el escritorio tiene dentro de *Evaluación física*.
 *
 * Como el escritorio, cada informe va a su columna de `consultas` (`rayosx`, `faringe` y `broncoscopia`; la
 * espirometría comparte `faringe` con la citología de ginecología). La escritura es la Action
 * `App\Actions\Consulta\GuardarNeumologiaDeLaConsulta`: ahí viven el rastro por columna y el last-write-wins.
 */
class ConsultaNeumologiaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarNeumologiaDeLaConsulta $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // Los tres informes son texto libre: el tope es el mismo de la evolución (no hay regla de negocio de largo).
        $datos = $request->validate([
            'rayosx'       => 'nullable|string|max:20000',
            'espirometria' => 'nullable|string|max:20000',
            'broncoscopia' => 'nullable|string|max:20000',
        ]);

        $guardadas = $guardar->ejecutar($consulta, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => PestanasDelExamen::NEUMOLOGIA,
        ]);

        return $guardadas > 0
            ? $destino->with('estado', 'Neumología guardada.')
            : $destino->with('estado', 'Sin cambios: los informes ya estaban así.');
    }
}
