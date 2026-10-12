<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarHospitalizacionDeLaConsulta;
use App\Actions\Consulta\GuardarHospitalizacionDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **La orden de hospitalización de una consulta** (WEB-5.3b, pestaña *Hospitalización* de las *Solicitudes*): guardar el lugar, el
 * diagnóstico provisional y las recomendaciones, y eliminarla.
 *
 * Una orden por consulta, con guardado explícito. La regla (al menos un texto, los topes) y el rastro en `sync_changes` son de la Action
 * `GuardarHospitalizacionDeLaConsulta`. Sólo del médico.
 */
class ConsultaHospitalizacionController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarHospitalizacionDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'hospital' => 'nullable|string|max:' . HospitalizacionDeLaConsulta::LARGO_HOSPITAL,
            'diagnostico' => 'nullable|string|max:' . HospitalizacionDeLaConsulta::LARGO_TEXTO,
            'recomendaciones' => 'nullable|string|max:' . HospitalizacionDeLaConsulta::LARGO_TEXTO,
        ], [
            'hospital.max' => 'El lugar a hospitalizar admite hasta ' . HospitalizacionDeLaConsulta::LARGO_HOSPITAL . ' caracteres.',
            'diagnostico.max' => 'El diagnóstico es demasiado largo.',
            'recomendaciones.max' => 'Las recomendaciones son demasiado largas.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['hospital'] ?? null, $datos['diagnostico'] ?? null, $datos['recomendaciones'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['hospital' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de la orden (de otro equipo) y no se pisó por completo: vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'La orden ya estaba guardada así.' : 'Orden de hospitalización guardada.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarHospitalizacionDeLaConsulta $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Orden de hospitalización eliminada.' : 'Esta consulta no tenía orden de hospitalización.');
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
            'solicitud' => PestanasDeSolicitudes::HOSPITALIZACION,
        ]);
    }
}
