<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarLaSolicitudDeTraumatologia;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Traumatologia\MapaDeLaOrdenDeIngreso;
use App\Clinica\Consulta\Traumatologia\MapaDelImplante;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La solicitud de implante y la orden de ingreso de una consulta** (Traumatología, T3, 2026-11-10): las dos pestañas de
 * *Solicitudes* que el escritorio tiene en `tabpage_23` y en la orden de ingreso.
 *
 * Una por consulta cada una (la clave del legado: `historia` + `consulta`), con guardado explícito. Todo es opcional: la
 * regla de la fila vacía y el rastro por columna en `sync_changes` son de la Action
 * `GuardarLaSolicitudDeTraumatologia`. Sólo del médico.
 */
class ConsultaTraumatologiaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardarImplante(Request $request, Consulta $consulta, GuardarLaSolicitudDeTraumatologia $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        // Sólo las columnas del mapa: lo que no esté en la solicitud no se escribe, aunque venga en el POST.
        $resultado = $guardar->implante($consulta, $request->only(MapaDelImplante::columnas()));

        return $this->volver($consulta, PestanasDeSolicitudes::IMPLANTE, $resultado['escribio']
            ? 'Solicitud de implante guardada.'
            : 'La solicitud ya estaba guardada así, o no tiene nada cargado.');
    }

    public function guardarOrdenDeIngreso(Request $request, Consulta $consulta, GuardarLaSolicitudDeTraumatologia $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $resultado = $guardar->ordenDeIngreso($consulta, $request->only(MapaDeLaOrdenDeIngreso::columnas()));

        return $this->volver($consulta, PestanasDeSolicitudes::ORDEN_DE_INGRESO, $resultado['escribio']
            ? 'Orden de ingreso guardada.'
            : 'La orden ya estaba guardada así, o no tiene nada cargado.');
    }

    private function volver(Consulta $consulta, string $pestana, string $mensaje)
    {
        return redirect()
            ->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
                'solicitud' => $pestana,
            ])
            ->with('estado', $mensaje);
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
}
