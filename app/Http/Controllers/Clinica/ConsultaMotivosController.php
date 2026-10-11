<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\AgregarMotivoALaConsulta;
use App\Actions\Consulta\AgregarMotivoAlCatalogo;
use App\Actions\Consulta\QuitarMotivoDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Los motivos de consulta de una consulta** (WEB-4.2, ítem 1 del panel del legado): agregar uno del
 * catálogo, crear el que falta y quitarlo.
 *
 * El catálogo y la sección los lee y los pinta `ConsultaController` (la sección vive **dentro** del
 * shell); acá están las tres escrituras, que son **acciones** y por eso viven en su controlador: la guía
 * de la web parte los controladores por responsabilidad, no por pantalla.
 *
 * Tres decisiones que se ven en el código:
 *
 *  - **Agregar y quitar son POST/DELETE con redirect**, no `fetch`: la sección se relee de la base y el
 *    resultado se cuenta en la franja de arriba ("Agregado «DOLOR PÉLVICO»"), que es lo que la guía de UI
 *    pide para el éxito de una acción. El **buscador** sí es JSON (`buscar`), porque filtra mientras se
 *    escribe y no escribe nada.
 *  - **El `reg_medico` sale del contexto**, nunca del formulario: el catálogo y la fila de
 *    `motivo_consulta_paciente` son del médico que está atendiendo.
 *  - **La regla de negocio no está acá**: agregar es `App\Actions\Consulta\AgregarMotivoALaConsulta` —la
 *    misma que usa el sync del móvil— y el alta del catálogo es `AgregarMotivoAlCatalogo`, la misma que
 *    usa `POST /api/app/motivos-consulta` (PLAN-WEB.md §3.15 y R3).
 */
class ConsultaMotivosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * Agregar un motivo del catálogo a la consulta.
     *
     * El `codemotivo` tiene que existir en el catálogo **del médico del contexto**: si no, es un 404
     * (el motivo no está en su catálogo y no hay nada que agregar). El catálogo se busca con
     * `MotivosDeLaConsulta::delCatalogo`, la misma lectura que usa la pantalla para mostrar la
     * descripción, así que lo que el buscador ofrece y lo que el POST acepta no pueden divergir.
     */
    public function agregar(
        Request $request,
        Consulta $consulta,
        MotivosDeLaConsulta $motivos,
        AgregarMotivoALaConsulta $agregar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codemotivo' => 'required|string|max:8',
        ]);

        $motivo = $motivos->delCatalogo($contexto['reg_medico'], $datos['codemotivo']);
        abort_if($motivo === null, 404);

        [$fila, $creado] = $agregar->ejecutar($consulta, $motivo, 'web');

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_MOTIVOS])
            ->with('estado', $creado
                ? 'Agregado «' . trim((string) $motivo->descripcion) . '» a la consulta.'
                : '«' . trim((string) $motivo->descripcion) . '» ya estaba en la consulta.');
    }

    /**
     * Quitar un motivo de la consulta (no del catálogo: el legado sólo desasocia, wiki
     * `04-historia-clinica.md`).
     */
    public function quitar(
        Request $request,
        Consulta $consulta,
        int $motivo,
        QuitarMotivoDeLaConsulta $quitar,
    ) {
        $consulta = $this->delContexto($request, $consulta);
        $this->consultas->motivoDeLaConsulta($consulta, $motivo);

        $quitar->ejecutar($motivo, null, 'web');

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_MOTIVOS])
            ->with('estado', 'Motivo quitado de la consulta. Sigue en tu catálogo.');
    }

    /**
     * **Crear el motivo que falta** desde el buscador (PLAN-WEB.md §3.15, decidido el 2026-10-10): si ya
     * existe uno igual —sin distinguir mayúsculas ni espacios de más— se usa el existente y **no se
     * duplica**, que es la misma regla del móvil y del endpoint del app.
     *
     * El motivo nuevo (o el existente) queda **agregado a la consulta** en el mismo paso: es lo que el
     * médico quiso al escribirlo, y hacerlo en dos pasos lo obligaría a buscarlo otra vez en la lista.
     *
     * Es **online-only** —el código de cuatro dígitos lo da el servidor— y la pantalla lo dice
     * (`SeccionDeMotivosVista::avisoDeConexion`).
     */
    public function crear(
        Request $request,
        Consulta $consulta,
        AgregarMotivoAlCatalogo $catalogo,
        AgregarMotivoALaConsulta $agregar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable` y no `required`: el texto vacío **no es un error de formulario**, es la regla de
            // negocio de la Action ("el motivo no tiene descripción") y tiene que llegar hasta ella. Con
            // `required`, Laravel la rechaza antes y el mensaje que ve el médico es otro (pasó al probar:
            // el POST redirigía al `back()` con "descripcion es obligatorio" en vez de al diálogo).
            'descripcion' => 'nullable|string|max:200',
        ]);

        try {
            [$motivo, $creado] = $catalogo->ejecutar(
                $contexto['medico'],
                $this->registrosDelMedico($contexto['medico']),
                (string) ($datos['descripcion'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            // El diálogo se vuelve a abrir para que lo escrito se pueda corregir (guía de UI: ante un
            // error no se borra lo escrito).
            return redirect()
                ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_MOTIVOS])
                ->withInput()
                ->withErrors(['descripcion' => $e->getMessage()])
                ->with('crear_motivo', true);
        }

        [$fila, $seAgrego] = $agregar->ejecutar($consulta, $motivo, 'web');

        $nombre = trim((string) $motivo->descripcion);

        // Los mensajes nombran siempre de dónde sale el motivo («tu catálogo»), que es lo que cambió: la
        // tabla `motivo_consulta_paciente` es la de la consulta y no se toca el catálogo al quitar.
        $mensaje = $creado
            ? ($seAgrego
                ? 'Creado «' . $nombre . '» en tu catálogo y agregado a la consulta.'
                : 'Creado «' . $nombre . '» en tu catálogo; ya estaba en la consulta.')
            : ($seAgrego
                ? 'Agregado «' . $nombre . '» de tu catálogo a la consulta.'
                : '«' . $nombre . '» ya estaba en tu catálogo y en la consulta.');

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_MOTIVOS])
            ->with('estado', $mensaje);
    }

    /**
     * El buscador en vivo de la sección (`Accept: application/json`): el catálogo del médico filtrado sin
     * distinguir tildes ni mayúsculas, **un motivo por nombre** (el catálogo del legado trae repetidos y
     * el de código más bajo es el que se ofrece, como el selector del móvil).
     *
     * Devuelve además `existe`: si lo escrito **ya es** un motivo del catálogo. Es lo que decide si el
     * buscador ofrece "crear el que falta" —con "DOLOR" escrito y "DOLOR PÉLVICO" en el catálogo sí se
     * ofrece, porque son motivos distintos—.
     */
    public function buscar(Request $request, Consulta $consulta, MotivosDeLaConsulta $motivos)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));
        $cargados = $motivos->deLaConsulta($contexto['reg_medico'], (int) $consulta->numhistoria, (int) $consulta->nroconsulta);
        $codigosCargados = $cargados->pluck('codigo')->all();

        $resultados = $motivos->buscar($contexto['reg_medico'], $buscar)->map(fn ($motivo) => [
            'codemotivo' => trim((string) $motivo->codemotivo),
            'descripcion' => trim((string) $motivo->descripcion),
            'yaEsta' => in_array(trim((string) $motivo->codemotivo), $codigosCargados, true),
        ])->values();

        return response()->json([
            'motivos' => $resultados,
            'existe' => $buscar !== '' && $motivos->existeEnElCatalogo($contexto['reg_medico'], $buscar),
            'buscar' => $buscar,
        ]);
    }

    /** La consulta del contexto (404 si es de otro médico). */
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
