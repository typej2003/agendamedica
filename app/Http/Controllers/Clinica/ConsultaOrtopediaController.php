<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarLaOrtopedia;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Traumatologia\MapaDeLaOrtopedia;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La plantilla ortopédica de una consulta** (Traumatología, 2026-11-09, pestaña *Ortopedia* de las *Solicitudes*):
 * guarda las casillas y los textos de la hoja «Calzados Ortopédicos».
 *
 * Una por consulta (`nrohistoria` + `nroconsulta`, la clave del legado) y con guardado explícito. Todo es opcional: la
 * regla de la fila vacía —sin nada cargado no se crea la fila— y el rastro por columna en `sync_changes` son de la
 * Action `GuardarLaOrtopedia`. Sólo del médico.
 */
class ConsultaOrtopediaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarLaOrtopedia $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        // Sólo las columnas del mapa: lo que no esté en la plantilla no se escribe, aunque venga en el POST.
        $datos = $request->only(MapaDeLaOrtopedia::columnas());

        $resultado = $guardar->ejecutar($consulta, $datos);

        return redirect()
            ->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
                'solicitud' => PestanasDeSolicitudes::ORTOPEDIA,
            ])
            ->with('estado', $resultado['escribio']
                ? 'Plantilla ortopédica guardada.'
                : 'La plantilla ya estaba guardada así, o no tiene nada cargado.');
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
