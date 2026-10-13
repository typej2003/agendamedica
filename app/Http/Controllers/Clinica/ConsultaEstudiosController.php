<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarSolicitudDeEstudios;
use App\Actions\Consulta\GuardarSolicitudDeEstudios;
use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
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
 * **La solicitud de estudios de una consulta** (WEB-5.4, pestaña *Estudios* de las *Solicitudes*): guardarla entera, eliminarla y el
 * buscador JSON del catálogo de estudios (los exámenes de tipo `002`).
 *
 * Misma forma que `ConsultaLaboratorioController`, con su Action (`GuardarSolicitudDeEstudios`) y sin rutinas: los estudios se piden de a
 * uno y salen en el orden de la lista. Sólo del médico.
 */
class ConsultaEstudiosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarSolicitudDeEstudios $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'estudios' => 'nullable|array|max:200',
            'estudios.*' => 'string|max:8',
            'observacion' => 'nullable|string|max:' . EstudiosDeLaConsulta::LARGO_OBSERVACION,
        ], [
            'estudios.array' => 'La lista de estudios no es válida.',
            'estudios.max' => 'La solicitud admite hasta 200 estudios.',
            'estudios.*.max' => 'Uno de los estudios tiene un código inválido.',
            'observacion.max' => 'El resumen clínico es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['estudios'] ?? [], $datos['observacion'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['observacion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente en la solicitud (de otro equipo) y no se pisó por completo: vuelve a cargarla y guárdala de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'La solicitud ya estaba guardada así.');
        }

        $partes = [];
        if ($resultado->agregados > 0) {
            $partes[] = $resultado->agregados . ($resultado->agregados === 1 ? ' estudio agregado' : ' estudios agregados');
        }
        if ($resultado->quitados > 0) {
            $partes[] = $resultado->quitados . ($resultado->quitados === 1 ? ' estudio quitado' : ' estudios quitados');
        }

        return $destino->with('estado', 'Solicitud de estudios guardada' . ($partes === [] ? '.' : ' (' . implode(', ', $partes) . ').'));
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarSolicitudDeEstudios $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Solicitud de estudios eliminada.' : 'Esta consulta no tenía solicitud de estudios.');
    }

    /** El buscador del catálogo de estudios: `?q=` (sin tildes); como mucho `MAX_RESULTADOS`, con el total de coincidencias. */
    public function catalogo(Request $request, Consulta $consulta, CatalogoParaPedirExamenes $catalogo): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));
        $coincidencias = $catalogo->buscar((string) $consulta->reg_medico, true, $buscar);

        return response()->json([
            'buscar' => $buscar,
            'tipo' => '',
            'total' => $coincidencias->count(),
            'examenes' => $coincidencias->take(CatalogoParaPedirExamenes::MAX_RESULTADOS)
                ->map(fn (array $examen) => ['codigo' => $examen['codigo'], 'nombre' => $examen['nombre'], 'tipo' => $examen['tipo']])
                ->values()->all(),
        ]);
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
            'solicitud' => PestanasDeSolicitudes::ESTUDIOS,
        ]);
    }
}
