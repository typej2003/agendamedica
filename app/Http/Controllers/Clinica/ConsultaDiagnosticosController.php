<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta;
use App\Actions\Consulta\ResultadoDeDiagnosticos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Los diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel del legado): escribir el detalle, agregar
 * uno del catálogo, quitarlo y **crear el que falta**.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=diagnostico`); acá están las escrituras, que
 * son acciones y por eso viven en un controlador propio (la guía de la web parte los controladores por
 * responsabilidad, no por pantalla: los motivos, la enfermedad, la evaluación y los antecedentes hacen lo
 * mismo).
 *
 * **Desde O-27 (2026-10-17) «Pasar» y «Quitar» son inmediatos**: cada uno es un `POST` que escribe en el acto
 * —una escritura por clic, con su rastro `source = web` y el last-write-wins de siempre—, igual que los
 * antecedentes desde O-07. Lo que sigue siendo explícito es el **detalle** de una fila que ya está: eso lo
 * aplica el botón «Guardar los diagnósticos».
 *
 * Dos cosas que este controlador no hace y está bien que no haga:
 *
 *  - **No sabe de la base de datos**: la regla es `App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta` (en
 *    `Actions\Consulta` porque la fila de `diagnostico_paciente` **es de la consulta**: se llavea por
 *    `nrohistoria` + `nroconsulta` + `codediagnostico`) y el alta del catálogo es
 *    `AgregarDiagnosticoAlCatalogo`.
 *  - **No inventa el `reg_medico` ni la consulta**: salen de la consulta ya resuelta contra el contexto, así
 *    que una consulta de otro consultorio da **404** sin confirmar que exista.
 */
class ConsultaDiagnosticosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar el detalle de las filas que ya están (O-27: lo agregado y lo quitado ya no vienen acá). */
    public function guardar(Request $request, Consulta $consulta, GuardarDiagnosticosDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate($this->reglas());

        $resultado = $guardar->ejecutar(
            $consulta,
            (array) ($datos['filas'] ?? []),
            [],
            [],
            null,
            'web',
        );

        return $this->volver($consulta, $resultado);
    }

    /**
     * **Pasar un diagnóstico del catálogo a la consulta** (O-27): una escritura, un clic, y la fila aparece en
     * la lista de la izquierda sin apretar el botón de guardar. La regla es la misma Action de siempre.
     *
     * Lo que se estaba buscando vuelve en la redirección para no perder el filtro del catálogo.
     */
    public function agregar(Request $request, Consulta $consulta, GuardarDiagnosticosDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:5',
            'buscar' => 'nullable|string|max:200',
        ]);

        $resultado = $guardar->ejecutar(
            $consulta,
            [],
            [trim((string) $datos['codigo'])],
            [],
            null,
            'web',
        );

        $destino = $this->destino($consulta, $datos);

        // Una fila nueva no puede chocar con el last-write-wins; si no se agregó nada es porque el código ya
        // estaba en la consulta o no es del catálogo del médico.
        if ($resultado->agregados === []) {
            return $resultado->bloqueados !== []
                ? $destino->with('error', 'Ese diagnóstico no está en tu catálogo, así que no se pasó.')
                : $destino->with('estado', 'Ese diagnóstico ya estaba en la consulta.');
        }

        return $destino->with('estado', 'Agregado ' . $this->listar($resultado->agregados) . ' a los diagnósticos de la consulta.');
    }

    /**
     * **Quitar un diagnóstico de la consulta** (O-27): una escritura, un clic, y la fila desaparece en el acto.
     * Se lleva **sólo la fila de la consulta**, nunca el catálogo (el `DELETE` del escritorio).
     */
    public function quitar(Request $request, Consulta $consulta, GuardarDiagnosticosDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'id' => 'required|integer|min:1',
            'buscar' => 'nullable|string|max:200',
        ]);

        $resultado = $guardar->ejecutar(
            $consulta,
            [],
            [],
            [(int) $datos['id']],
            null,
            'web',
        );

        $destino = $this->destino($consulta, $datos);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los diagnósticos y quítalo de nuevo.');
        }

        if ($resultado->quitados === []) {
            return $destino->with('estado', 'Ese diagnóstico ya no estaba en la consulta.');
        }

        return $destino->with('estado', 'Quitado ' . $this->listar($resultado->quitados) . ' de los diagnósticos de la consulta.');
    }

    /**
     * **Crear el diagnóstico que falta** en el catálogo (`w_nuevo_diagnostico` del escritorio): el código lo
     * da el servidor y la descripción se guarda en mayúsculas. Si ya existe uno igual, se usa el existente
     * y **no se duplica** (la misma regla del móvil con los motivos y de WEB-4.2).
     *
     * Es **online-only** (PLAN-WEB.md §3.15) y el diagnóstico nuevo queda **agregado a la consulta** en el
     * mismo paso (como los antecedentes): desde O-27 no hay nada tildado que esperar, así que hacerlo en dos
     * pasos obligaría al médico a apretar «Guardar» para ver lo que ya creó.
     */
    public function crear(
        Request $request,
        Consulta $consulta,
        AgregarDiagnosticoAlCatalogo $catalogo,
        GuardarDiagnosticosDeLaConsulta $guardar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable` y no `required`, igual que el alta de motivos y de antecedentes: el texto vacío no
            // es un error de formulario, es la regla de negocio de la Action y el mensaje tiene que ser el suyo.
            'descripcion' => 'nullable|string|max:200',
        ]);

        try {
            [$diagnostico, $creado] = $catalogo->ejecutar(
                $contexto['medico'],
                $this->registrosDelMedico($contexto['medico']),
                (string) ($datos['descripcion'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            // La sección se vuelve a mostrar con lo escrito para que se pueda corregir (guía de UI §3: ante
            // un error no se borra lo escrito).
            return redirect()
                ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO])
                ->withInput()
                ->withErrors(['descripcion' => $e->getMessage()]);
        }

        $codigo = trim((string) $diagnostico->codediagnostico);
        $nombre = trim((string) $diagnostico->descripcion);

        $resultado = $guardar->ejecutar($consulta, [], [$codigo], [], null, 'web');

        $mensaje = $resultado->agregados === []
            // El código ya estaba en la consulta: la Action no lo duplica. Pasa si el médico crea el
            // diagnóstico que ya tenía pasado (el catálogo no lo ofrece, el alta sí puede).
            ? ($creado ? 'Creado «' . $nombre . '» en tu catálogo' : '«' . $nombre . '» ya estaba en tu catálogo')
                . '; la consulta ya lo tenía.'
            : ($creado ? 'Creado «' . $nombre . '» en tu catálogo' : '«' . $nombre . '» ya estaba en tu catálogo')
                . ' y agregado a los diagnósticos de la consulta.';

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO])
            ->with('estado', $mensaje);
    }

    /**
     * Las reglas del formulario de guardado: sólo el detalle de las filas.
     *
     * `filas` llega por **id de `diagnostico_paciente`** (el detalle es la única columna editable). Lleva el
     * tope de la columna (`varchar(100)`) para no aceptar un cuerpo absurdo.
     */
    private function reglas(): array
    {
        return [
            'filas' => 'nullable|array|max:200',
            'filas.*.detalle' => 'nullable|string|max:' . GuardarDiagnosticosDeLaConsulta::LARGO_DETALLE,
        ];
    }

    /**
     * La vuelta a la sección con la búsqueda que el médico estaba mirando (O-27: el «Pasar» y el «Quitar»
     * conservan el filtro del catálogo).
     *
     * @param  array<string,mixed>  $datos  el `buscar` del formulario
     */
    private function destino(Consulta $consulta, array $datos): \Illuminate\Http\RedirectResponse
    {
        $parametros = [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO];

        if (trim((string) ($datos['buscar'] ?? '')) !== '') {
            $parametros['buscar'] = trim((string) $datos['buscar']);
        }

        return redirect()->route('clinica.consulta', $parametros);
    }

    /**
     * La vuelta a la sección con el mensaje de lo que pasó. El orden de los avisos importa: si el sync
     * bloqueó algo, se dice eso primero (no se puede tapar con un "Guardado" que no fue).
     */
    private function volver(Consulta $consulta, ResultadoDeDiagnosticos $resultado)
    {
        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO,
        ]);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los diagnósticos y guárdalos de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'Los diagnósticos no tenían cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** "Agregado 1, quitado 2 y guardado 3": lo que pasó, con sus cuentas, no un "Operación exitosa". */
    private function resumen(ResultadoDeDiagnosticos $resultado): string
    {
        $partes = [];

        if ($resultado->agregados !== []) {
            $partes[] = 'agregado' . (count($resultado->agregados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->agregados);
        }
        if ($resultado->quitados !== []) {
            $partes[] = 'quitado' . (count($resultado->quitados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->quitados);
        }
        if ($resultado->guardados !== []) {
            $partes[] = 'guardado' . (count($resultado->guardados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->guardados);
        }

        return 'Diagnósticos actualizados: ' . implode(', ', $partes) . '.';
    }

    /**
     * "«DOLOR PELVICO»", "«A» y «B»": nombra lo que pasó con cada diagnóstico, no con su id.
     *
     * @param  array<int,string>  $nombres
     */
    private function listar(array $nombres): string
    {
        $etiquetas = array_map(fn ($nombre) => '«' . $nombre . '»', $nombres);

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
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
