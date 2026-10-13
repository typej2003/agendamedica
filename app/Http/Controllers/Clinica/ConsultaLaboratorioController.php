<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarOrdenDeLaboratorio;
use App\Actions\Consulta\GuardarOrdenDeLaboratorio;
use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **La orden de exámenes de laboratorio de una consulta** (WEB-5.4, pestaña *Laboratorio* de las *Solicitudes*): guardarla entera,
 * eliminarla y los dos JSON que alimentan el editor (el buscador del catálogo y las rutinas).
 *
 * La pestaña se **lee** en `ConsultaController::ver` (`?seccion=solicitudes&solicitud=laboratorio`); acá están las escrituras y los
 * buscadores, que son acciones. La regla (qué es una orden, que un examen nuevo salga del catálogo, qué se crea y qué se borra) y el
 * rastro en `sync_changes` son de la Action `GuardarOrdenDeLaboratorio`. Sólo del médico: el `reg_medico` sale del contexto y una consulta
 * de otro consultorio da 404.
 */
class ConsultaLaboratorioController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarOrdenDeLaboratorio $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'examenes' => 'nullable|array|max:500',
            'examenes.*' => 'string|max:8',
            'observacion' => 'nullable|string|max:' . LaboratorioDeLaConsulta::LARGO_OBSERVACION,
        ], [
            'examenes.array' => 'La lista de exámenes no es válida.',
            'examenes.max' => 'La orden admite hasta 500 exámenes.',
            'examenes.*.max' => 'Uno de los exámenes tiene un código inválido.',
            'observacion.max' => 'El resumen clínico es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['examenes'] ?? [], $datos['observacion'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['observacion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente en el resumen clínico (de otro equipo) y no se pisó: vuelve a cargar la orden y guárdala de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'La orden ya estaba guardada así.');
        }

        $partes = [];
        if ($resultado->agregados > 0) {
            $partes[] = $resultado->agregados . ($resultado->agregados === 1 ? ' examen agregado' : ' exámenes agregados');
        }
        if ($resultado->quitados > 0) {
            $partes[] = $resultado->quitados . ($resultado->quitados === 1 ? ' examen quitado' : ' exámenes quitados');
        }

        return $destino->with('estado', 'Orden de laboratorio guardada' . ($partes === [] ? '.' : ' (' . implode(', ', $partes) . ').'));
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarOrdenDeLaboratorio $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Orden de laboratorio eliminada.' : 'Esta consulta no tenía orden de laboratorio.');
    }

    /** El buscador del catálogo: `?q=` (sin tildes) y `?tipo=`; como mucho `MAX_RESULTADOS`, con el total de coincidencias. */
    public function catalogo(Request $request, Consulta $consulta, CatalogoParaPedirExamenes $catalogo): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));
        $tipo = trim((string) $request->query('tipo', ''));
        $coincidencias = $catalogo->buscar((string) $consulta->reg_medico, false, $buscar, $tipo);

        return response()->json([
            'buscar' => $buscar,
            'tipo' => $tipo,
            'total' => $coincidencias->count(),
            'examenes' => $coincidencias->take(CatalogoParaPedirExamenes::MAX_RESULTADOS)->map(fn (array $examen) => $this->paraElJson($examen))->values()->all(),
        ]);
    }

    /** Los exámenes de una rutina (un tipo de examen completo); 404 si el tipo no es una rutina del médico. */
    public function rutina(Request $request, Consulta $consulta, string $tipo, CatalogoParaPedirExamenes $catalogo): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);
        $regMedico = (string) $consulta->reg_medico;

        $examenes = $catalogo->deLaRutina($regMedico, $tipo);
        abort_if($examenes === null, 404);

        return response()->json([
            'codigo' => trim($tipo),
            'nombre' => $catalogo->tipos($regMedico)[trim($tipo)] ?? trim($tipo),
            'examenes' => $examenes->map(fn (array $examen) => $this->paraElJson($examen))->values()->all(),
        ]);
    }

    /** @param array{codigo:string, nombre:string, codetipo:?string, tipo:?string} $examen */
    private function paraElJson(array $examen): array
    {
        return ['codigo' => $examen['codigo'], 'nombre' => $examen['nombre'], 'tipo' => $examen['tipo']];
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
            'solicitud' => PestanasDeSolicitudes::LABORATORIO,
        ]);
    }
}
