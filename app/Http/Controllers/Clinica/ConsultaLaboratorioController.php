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
 * **La orden de exámenes de laboratorio de una consulta** (WEB-5.4, pestaña *Laboratorio* de las *Solicitudes*): agregar un examen,
 * quitarlo, guardar el resumen clínico, eliminar la orden entera y los dos JSON que alimentan el editor de la orden de la pareja (el
 * buscador del catálogo y las rutinas).
 *
 * ## Como Motivos de consulta (O-29)
 *
 * La pestaña se pinta con el catálogo a la vista y **cada clic escribe**: `agregar` (un examen, o todos los de una rutina) y `quitar`
 * son POST/DELETE con redirect, así que el ítem aparece y desaparece de la lista en el acto y la sección se relee de la base. El
 * `POST` de la ruta sin sufijo guarda **sólo el «Resumen clínico»**, que sigue siendo un formulario con su botón.
 *
 * La regla (qué es una orden, que un examen nuevo salga del catálogo, qué se crea y qué se borra) y el rastro en `sync_changes` son de
 * la Action `GuardarOrdenDeLaboratorio`. Sólo del médico: el `reg_medico` sale del contexto y una consulta de otro consultorio da 404.
 */
class ConsultaLaboratorioController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * Guardar el **resumen clínico** (O-29): es lo único que sigue siendo un formulario con su botón.
     *
     * Los exámenes ya se escribieron al agregarlos o quitarlos; el `POST` **no** manda la lista ni borra nada que no haya tocado el
     * médico (la tabla se sube por fila y el escritorio también la escribe).
     */
    public function guardar(Request $request, Consulta $consulta, GuardarOrdenDeLaboratorio $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'observacion' => 'nullable|string|max:' . LaboratorioDeLaConsulta::LARGO_OBSERVACION,
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

    /** **Agregar un examen** del catálogo a la orden (O-29): la escritura de un clic en «Pasar». */
    public function agregar(Request $request, Consulta $consulta, GuardarOrdenDeLaboratorio $agregar, CatalogoParaPedirExamenes $catalogo)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:8',
        ], [
            'codigo.required' => 'Elige el examen que quieres agregar a la orden.',
            'codigo.max' => 'Ese examen no es válido.',
        ]);

        $codigo = trim((string) $datos['codigo']);

        // La lectura del catálogo, para el nombre del aviso y para no confirmar un código que no existe (404, como motivos). La
        // **regla** —que un examen nuevo tiene que ser del catálogo del médico— vive en la Action, que la vuelve a comprobar.
        $examen = $catalogo->resolver((string) $consulta->reg_medico, false, [$codigo])[$codigo] ?? null;
        abort_if($examen === null, 404);

        try {
            $resultado = $agregar->agregar($consulta, [$codigo]);
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        return $this->destino($consulta)->with('estado', $resultado->agregados > 0
            ? 'Agregado «' . $examen['nombre'] . '» a la orden.'
            : '«' . $examen['nombre'] . '» ya estaba en la orden.');
    }

    /** **Quitar un examen** de la orden (O-29): la baja de un clic, de esa fila y de ninguna otra. */
    public function quitar(Request $request, Consulta $consulta, GuardarOrdenDeLaboratorio $quitar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:8',
        ], [
            'codigo.required' => 'Elige el examen que quieres quitar de la orden.',
        ]);

        $quitados = $quitar->quitar($consulta, (string) $datos['codigo']);

        return $this->destino($consulta)->with('estado', $quitados > 0 ? 'Examen quitado de la orden.' : 'Ese examen ya no estaba en la orden.');
    }

    /**
     * **Pasar una rutina completa** (O-29): todos los exámenes de un tipo, de una vez. Es la misma alta de un examen, repetida —
     * la comprobación de catálogo y el rastro son los de la Action.
     */
    public function rutinaAgregar(Request $request, Consulta $consulta, string $tipo, GuardarOrdenDeLaboratorio $agregar, CatalogoParaPedirExamenes $catalogo)
    {
        $consulta = $this->delContexto($request, $consulta);
        $regMedico = (string) $consulta->reg_medico;

        $examenes = $catalogo->deLaRutina($regMedico, $tipo);
        abort_if($examenes === null, 404);

        try {
            $resultado = $agregar->agregar($consulta, $examenes->pluck('codigo')->all());
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        $nombre = $catalogo->tipos($regMedico)[trim($tipo)] ?? trim($tipo);

        return $this->destino($consulta)->with('estado', $resultado->agregados > 0
            ? 'Rutina «' . $nombre . '» agregada: ' . $resultado->agregados . ($resultado->agregados === 1 ? ' examen nuevo.' : ' exámenes nuevos.')
            : 'Los exámenes de «' . $nombre . '» ya estaban en la orden.');
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
