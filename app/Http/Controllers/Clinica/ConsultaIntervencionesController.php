<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarIntervencionDeLaConsulta;
use App\Actions\Consulta\QuitarIntervencionDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\IntervencionesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\QuirurgicoVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Intervenciones de la consulta de cirugía pediátrica** (2026-10-19): alta/edición y baja de una fila de
 * `interven_consulta` (clave `historia` + `consulta` + `codigo_inter`).
 *
 * Las reglas de escritura viven en las Actions `GuardarIntervencionDeLaConsulta` y
 * `QuitarIntervencionDeLaConsulta` (sólo lo que cambió, rastro por columna y `deleted` en la baja). Acá sólo se
 * valida el HTTP y se vuelve a la pestaña de intervenciones.
 */
class ConsultaIntervencionesController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarIntervencionDeLaConsulta $guardar)
    {
        $consulta = $this->resolver($request, $consulta);

        $datos = $request->validate([
            'codigo'      => ['required', 'string', 'max:3'],
            'descripcion' => ['required', 'string', 'max:100'],
            'detalle'     => ['nullable', 'string', 'max:20000'],
        ]);

        $guardadas = $guardar->ejecutar($consulta, $datos, null, 'web');

        return $this->volver($consulta)->with(
            'estado',
            $guardadas > 0 ? 'Intervención guardada.' : 'Sin cambios: la intervención ya estaba así.',
        );
    }

    public function quitar(Request $request, Consulta $consulta, string $codigo, QuitarIntervencionDeLaConsulta $quitar)
    {
        $consulta = $this->resolver($request, $consulta);
        $quitada = $quitar->ejecutar($consulta, $codigo, null, 'web');

        return $this->volver($consulta)->with(
            'estado',
            $quitada ? 'Intervención quitada.' : 'Esa intervención no estaba en la consulta.',
        );
    }

    private function resolver(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }

    private function volver(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_QUIRURGICO,
            'pestana' => QuirurgicoVista::INTERVENCIONES,
        ]);
    }
}
