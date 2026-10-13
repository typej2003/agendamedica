<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarConstanciaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La constancia de una consulta** (WEB-5.3a, pestaña *Constancia* de las *Solicitudes*): guardar la observación del
 * médico y los diagnósticos que editó, y quitar lo propio.
 *
 * Es **una por consulta** y se guarda con un botón (texto clínico, sin autoguardado). El HTTP se valida acá; la escritura, el
 * rastro en `sync_changes` y el «no se crea una fila vacía» son de la Action `GuardarConstanciaDeLaConsulta`. Como el resto
 * de las secciones de la consulta, es **sólo del médico** (grupo `role:Root|Medico`): la secretaría recibe 403 y una
 * consulta de otro consultorio, 404.
 */
class ConsultaConstanciaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarConstanciaDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'diagnosticos' => 'nullable|string|max:' . GuardarConstanciaDeLaConsulta::LARGO_MAXIMO,
            'observacion' => 'nullable|string|max:' . GuardarConstanciaDeLaConsulta::LARGO_MAXIMO,
        ], [
            'diagnosticos.max' => 'Los diagnósticos de la constancia son demasiado largos.',
            'observacion.max' => 'La observación de la constancia es demasiado larga.',
        ]);

        $resultado = $guardar->ejecutar($consulta, $datos['diagnosticos'] ?? null, $datos['observacion'] ?? null);

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de la constancia (de otro equipo) y no se pisó: vuelve a cargarla y guárdala de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'La constancia no tiene nada propio que guardar: se imprime con el texto base y los diagnósticos de la consulta.');
        }

        return $destino->with('estado', 'Constancia guardada.');
    }

    /** «Quitar lo propio»: la observación y los diagnósticos editados. La constancia vuelve a su texto base. */
    public function eliminar(Request $request, Consulta $consulta, GuardarConstanciaDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $resultado = $guardar->ejecutar($consulta, null, null);

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de la constancia (de otro equipo) y no se quitó: vuelve a cargarla.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'La constancia no tenía nada propio que quitar.'
            : 'Se quitó lo propio de la constancia: vuelve a su texto base.');
    }

    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // El `{consulta}` de la ruta no se usa como modelo: se vuelve a resolver contra el contexto (404 si es de otro médico).
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
            'solicitud' => PestanasDeSolicitudes::CONSTANCIA,
        ]);
    }
}
