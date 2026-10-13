<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarInformeDeLaConsulta;
use App\Actions\Consulta\GuardarInformeDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **El informe médico de una consulta** (WEB-5.3a, pestaña *Informe médico* de las *Solicitudes*): guardar el *Para*, el
 * texto y la fecha, y eliminarlo.
 *
 * Un informe por consulta, con guardado explícito. Las plantillas y los informes anteriores **no pasan por acá**: llenan el
 * editor en el navegador (`consulta-solicitudes.js`) y esto recibe el texto ya terminado. La regla del texto (obligatorio, no
 * el `.` de la plantilla) y el rastro en `sync_changes` son de la Action `GuardarInformeDeLaConsulta`. Sólo del médico.
 */
class ConsultaInformeController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarInformeDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'para' => 'nullable|string|max:' . InformeDeLaConsulta::LARGO_PARA,
            'descripcion' => 'required|string|max:' . GuardarInformeDeLaConsulta::LARGO_MAXIMO,
            'fecha' => 'nullable|date_format:Y-m-d',
        ], [
            'para.max' => 'El «Para» admite hasta ' . InformeDeLaConsulta::LARGO_PARA . ' caracteres.',
            'descripcion.required' => 'Escribe el texto del informe, elige una plantilla o copia un informe anterior.',
            'descripcion.max' => 'El texto del informe es demasiado largo.',
            'fecha.date_format' => 'La fecha del informe no es válida.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['para'] ?? null, $datos['descripcion'], $datos['fecha'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['descripcion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente del informe (de otro equipo) y no se pisó por completo: vuelve a cargarlo y guárdalo de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'El informe ya estaba guardado así.' : 'Informe guardado.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarInformeDeLaConsulta $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitados = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitados > 0 ? 'Informe eliminado.' : 'Esta consulta no tenía informe.');
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
            'solicitud' => PestanasDeSolicitudes::INFORME,
        ]);
    }
}
