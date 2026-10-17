<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarOrdenDeLaPareja;
use App\Actions\Consulta\GuardarOrdenDeLaPareja;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\LaboratorioDeLaPareja;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **La orden de laboratorio de la pareja** (WEB-11.21, el botón «Pareja» de la pestaña Laboratorio): guardarla entera y eliminarla. La
 * pantalla se **lee** en `ConsultaController::ver` (`?seccion=solicitudes&solicitud=pareja`); el buscador del catálogo y las rutinas son
 * los de la orden de la paciente (`ConsultaLaboratorioController`). La regla es la Action `GuardarOrdenDeLaPareja`.
 */
class ConsultaLaboratorioParejaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarOrdenDeLaPareja $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'examenes' => 'nullable|array|max:500',
            'examenes.*' => 'string|max:8',
            'cedula' => 'nullable|string|max:' . LaboratorioDeLaPareja::LARGO_CEDULA,
            'nombre' => 'nullable|string|max:' . LaboratorioDeLaPareja::LARGO_NOMBRE,
            'observacion' => 'nullable|string|max:' . LaboratorioDeLaPareja::LARGO_OBSERVACION,
        ], [
            'examenes.array' => 'La lista de exámenes no es válida.',
            'examenes.max' => 'La orden admite hasta 500 exámenes.',
            'examenes.*.max' => 'Uno de los exámenes tiene un código inválido.',
            'cedula.max' => 'La cédula es demasiado larga.',
            'nombre.max' => 'El nombre es demasiado largo.',
            'observacion.max' => 'El resumen clínico es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['examenes'] ?? [], $datos['cedula'] ?? null, $datos['nombre'] ?? null, $datos['observacion'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['nombre' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente en la orden de la pareja (de otro equipo) y no se pisó: vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'La orden de la pareja ya estaba guardada así.' : 'Orden de la pareja guardada.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarOrdenDeLaPareja $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Orden de la pareja eliminada.' : 'Esta consulta no tenía orden de la pareja.');
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
            'solicitud' => PestanasDeSolicitudes::PAREJA,
        ]);
    }
}
