<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarReposoDeLaConsulta;
use App\Actions\Consulta\GuardarReposoDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **El reposo de una consulta** (WEB-5.3a, pestaña *Reposo* de las *Solicitudes*): guardar los días, la fecha de inicio y
 * la justificación, y eliminarlo.
 *
 * Un reposo por consulta, con guardado explícito. La regla de qué es un reposo de verdad (un día solo necesita
 * justificación) y el rastro en `sync_changes` son de la Action `GuardarReposoDeLaConsulta`; acá se valida el HTTP y se
 * devuelve el rechazo de la Action como error del campo que corresponde. Sólo del médico (`role:Root|Medico`).
 */
class ConsultaReposoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarReposoDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'dias' => 'required|integer|min:1|max:' . ReposoDeLaConsulta::DIAS_MAXIMO,
            'desde' => 'required|date_format:Y-m-d',
            'observacion' => 'nullable|string|max:' . GuardarReposoDeLaConsulta::LARGO_MAXIMO,
        ], [
            'dias.required' => 'Indica cuántos días de reposo.',
            'dias.integer' => 'Los días de reposo son un número entero.',
            'dias.min' => 'El reposo es de al menos 1 día.',
            'dias.max' => 'El reposo no puede pasar de ' . ReposoDeLaConsulta::DIAS_MAXIMO . ' días.',
            'desde.required' => 'Indica desde qué día es el reposo.',
            'desde.date_format' => 'La fecha de inicio del reposo no es válida.',
            'observacion.max' => 'La justificación del reposo es demasiado larga.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, (int) $datos['dias'], $datos['desde'], $datos['observacion'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            // La regla del reposo de un solo día sin justificación cuelga del campo de la justificación.
            throw ValidationException::withMessages(['observacion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente del reposo (de otro equipo) y no se pisó por completo: vuelve a cargarlo y guárdalo de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'El reposo ya estaba guardado así.' : 'Reposo guardado.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarReposoDeLaConsulta $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitados = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitados > 0 ? 'Reposo eliminado.' : 'Esta consulta no tenía reposo.');
    }

    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }

    private function destino(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
            'solicitud' => PestanasDeSolicitudes::REPOSO,
        ]);
    }
}
