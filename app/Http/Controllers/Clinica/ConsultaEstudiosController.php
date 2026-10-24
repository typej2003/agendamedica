<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarSolicitudDeEstudios;
use App\Actions\Consulta\GuardarSolicitudDeEstudios;
use App\Clinica\Busquedas\BusquedaConCorrector;
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
 * **La solicitud de estudios de una consulta** (WEB-5.4, pestaña *Estudios* de las *Solicitudes*): agregar un estudio, quitarlo, guardar el
 * resumen clínico, eliminar la solicitud entera y el buscador JSON del catálogo (los exámenes de tipo `002`).
 *
 * Como Motivos de consulta (O-29): el catálogo a la vista con un «Pasar» por fila y **cada clic escribe** (`agregar` / `quitar`); el
 * `POST` de la ruta sin sufijo guarda **sólo el «Resumen clínico»**. Misma forma que `ConsultaLaboratorioController`, con su Action
 * (`GuardarSolicitudDeEstudios`) y sin rutinas: los estudios se piden de a uno y salen en el orden de la lista. Sólo del médico.
 */
class ConsultaEstudiosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar el **resumen clínico** (O-29): lo único que sigue siendo un formulario con su botón. */
    public function guardar(Request $request, Consulta $consulta, GuardarSolicitudDeEstudios $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'observacion' => 'nullable|string|max:' . EstudiosDeLaConsulta::LARGO_OBSERVACION,
        ], [
            'observacion.max' => 'El resumen clínico es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->guardarResumen($consulta, $datos['observacion'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['observacion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente en el resumen clínico (de otro equipo) y no se pisó: vuelve a cargarlo y guárdalo de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'El resumen clínico ya estaba guardado así.');
        }

        return $destino->with('estado', 'Resumen clínico guardado.');
    }

    /** **Agregar un estudio** del catálogo a la solicitud (O-29): la escritura de un clic en «Pasar». */
    public function agregar(Request $request, Consulta $consulta, GuardarSolicitudDeEstudios $agregar, CatalogoParaPedirExamenes $catalogo)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:8',
        ], [
            'codigo.required' => 'Elige el estudio que quieres agregar a la solicitud.',
            'codigo.max' => 'Ese estudio no es válido.',
        ]);

        $codigo = trim((string) $datos['codigo']);

        // La lectura del catálogo, para el aviso y para no confirmar un código que no existe (404). La **regla** vive en la Action.
        $estudio = $catalogo->resolver((string) $consulta->reg_medico, true, [$codigo])[$codigo] ?? null;
        abort_if($estudio === null, 404);

        try {
            $resultado = $agregar->agregar($consulta, [$codigo]);
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        return $this->destino($consulta)->with('estado', $resultado->agregados > 0
            ? 'Agregado «' . $estudio['nombre'] . '» a la solicitud.'
            : '«' . $estudio['nombre'] . '» ya estaba en la solicitud.');
    }

    /** **Quitar un estudio** de la solicitud (O-29): la baja de un clic, de esa fila y de ninguna otra. */
    public function quitar(Request $request, Consulta $consulta, GuardarSolicitudDeEstudios $quitar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:8',
        ], [
            'codigo.required' => 'Elige el estudio que quieres quitar de la solicitud.',
        ]);

        $quitados = $quitar->quitar($consulta, (string) $datos['codigo']);

        return $this->destino($consulta)->with('estado', $quitados > 0 ? 'Estudio quitado de la solicitud.' : 'Ese estudio ya no estaba en la solicitud.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarSolicitudDeEstudios $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Solicitud de estudios eliminada.' : 'Esta consulta no tenía solicitud de estudios.');
    }

    /** El buscador del catálogo de estudios: `?q=` (sin tildes); como mucho `MAX_RESULTADOS`, con el total de coincidencias. */
    public function catalogo(Request $request, Consulta $consulta, CatalogoParaPedirExamenes $catalogo, BusquedaConCorrector $corrector): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));

        // Primero lo escrito; si no encontró nada, el término corregido (PecleSearch).
        [$coincidencias, $correccion] = $corrector->con(
            fn (string $termino) => $catalogo->buscar((string) $consulta->reg_medico, true, $termino),
            'examenes',
            $buscar,
            $request->boolean('literal'),
        );

        return response()->json([
            'buscar' => $correccion->aplicado,
            'tipo' => '',
            'total' => $coincidencias->count(),
            'examenes' => $coincidencias->take(CatalogoParaPedirExamenes::MAX_RESULTADOS)
                ->map(fn (array $examen) => ['codigo' => $examen['codigo'], 'nombre' => $examen['nombre'], 'tipo' => $examen['tipo']])
                ->values()->all(),
            'correccion' => $correccion->corregida()
                ? ['escrito' => $correccion->escrito, 'aplicado' => $correccion->aplicado]
                : null,
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
