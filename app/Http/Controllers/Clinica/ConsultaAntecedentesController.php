<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\AgregarAntecedenteAlCatalogo;
use App\Actions\Pacientes\GuardarAntecedentes;
use App\Actions\Pacientes\ResultadoDeAntecedentes;
use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4, ítem 3 del panel del legado): escribir el
 * detalle, marcar la alerta, agregar uno del catálogo y quitarlo.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=antecedentes`); acá están las escrituras,
 * que son acciones y por eso viven en un controlador propio (la guía de la web parte los controladores por
 * responsabilidad, no por pantalla: la enfermedad actual y la evaluación física hacen lo mismo).
 *
 * **Desde O-07 (2026-10-17) el agregado y la baja son inmediatos**: *Agregar* y *Quitar* son un `POST` cada
 * uno y escriben en el acto (una escritura por clic, con su rastro y el last-write-wins de siempre), como
 * «Pasar» en motivos y diagnósticos. Lo que sigue siendo explícito es el **detalle** y la **casilla de
 * Alerta** de las filas que ya están: eso lo aplica el botón «Guardar los antecedentes», porque es texto que
 * el médico está escribiendo y no un clic sobre el catálogo.
 *
 * Dos cosas que este controlador no hace y está bien que no haga:
 *
 *  - **No sabe de la base de datos**: la regla es `App\Actions\Pacientes\GuardarAntecedentes` (está en
 *    `Pacientes` porque **los antecedentes son del paciente**, no de la consulta; el porqué, con la cita
 *    del `.srw`, está en el encabezado de la Action).
 *  - **No inventa el `reg_medico` ni el número de historia**: salen de la consulta ya resuelta contra el
 *    contexto, así que una consulta de otro consultorio da **404** sin confirmar que exista.
 */
class ConsultaAntecedentesController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * **Guardar el detalle y la casilla de Alerta** de las filas que ya están (O-07: el agregado y la baja
     * dejaron de venir en este formulario, ahora son `agregar()` y `quitar()`).
     *
     * El formulario nunca deduce una baja de lo que no vino: la pantalla no puede borrar lo que no mostró.
     */
    public function guardar(Request $request, Consulta $consulta, GuardarAntecedentes $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate($this->reglas());

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            (array) ($datos['filas'] ?? []),
            [],
            [],
            null,
            'web',
            // La consulta desde la que se escribe, para el registro de movimientos (2026-10-20).
            (int) $consulta->id,
        );

        return $this->volver($consulta, $resultado);
    }

    /**
     * **Agregar un antecedente del catálogo al paciente** (O-07): una escritura, un clic, y la fila aparece en
     * la lista sin apretar el botón de guardar. La regla es la misma Action de siempre (`GuardarAntecedentes`),
     * que copia el nombre y el tipo como el `INSERT` del escritorio y deja su rastro `source = web`.
     *
     * El `tipo` y lo que se estaba buscando vuelven en la redirección para no perder el chip ni el filtro.
     */
    public function agregar(Request $request, Consulta $consulta, GuardarAntecedentes $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:4',
            'tipo' => 'nullable|string|max:2',
            'buscar' => 'nullable|string|max:200',
        ]);

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            [],
            [trim((string) $datos['codigo'])],
            [],
            null,
            'web',
            // La consulta desde la que se escribe, para el registro de movimientos (2026-10-20).
            (int) $consulta->id,
        );

        $destino = $this->destino($consulta, $datos);

        // Una fila nueva no puede chocar con el last-write-wins (no existía), así que si no se agregó nada
        // es porque el código ya estaba en el paciente o no es del catálogo del médico. Las dos cosas se
        // dicen distinto, y **nunca** se disfrazan de "otro equipo lo editó".
        if ($resultado->agregados === []) {
            return $resultado->bloqueados !== []
                ? $destino->with('error', 'Ese antecedente no está en tu catálogo de antecedentes, así que no se agregó.')
                : $destino->with('estado', 'Ese antecedente ya estaba cargado en el paciente.');
        }

        return $destino->with('estado', 'Agregado ' . $this->listar($resultado->agregados) . ' a los antecedentes del paciente.');
    }

    /**
     * **Quitar un antecedente del paciente** (O-07): una escritura, un clic, y la fila desaparece de la lista
     * en el acto. Se lleva **sólo la relación** del paciente, nunca el catálogo (el `DELETE` del escritorio).
     */
    public function quitar(Request $request, Consulta $consulta, GuardarAntecedentes $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'codigo' => 'required|string|max:4',
            'tipo' => 'nullable|string|max:2',
            'buscar' => 'nullable|string|max:200',
        ]);

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            [],
            [],
            [trim((string) $datos['codigo'])],
            null,
            'web',
            // La consulta desde la que se escribe, para el registro de movimientos (2026-10-20).
            (int) $consulta->id,
        );

        $destino = $this->destino($consulta, $datos);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los antecedentes y quítalo de nuevo.');
        }

        if ($resultado->quitados === []) {
            return $destino->with('estado', 'Ese antecedente ya no estaba en el paciente.');
        }

        return $destino->with('estado', 'Quitado ' . $this->listar($resultado->quitados) . ' de los antecedentes del paciente.');
    }

    /**
     * **Crear el antecedente que falta** en el catálogo (`w_nuevo_antecedente` del escritorio): el código
     * lo da el servidor, la descripción se guarda en mayúsculas y el tipo se elige del catálogo.
     *
     * Es **online-only**, como el alta de motivos (PLAN-WEB.md §3.15). El antecedente nuevo queda
     * **agregado al paciente** en el mismo paso, que es lo que el médico quiso al escribirlo; hacerlo en
     * dos pasos lo obligaría a buscarlo otra vez.
     */
    public function crear(
        Request $request,
        Consulta $consulta,
        AgregarAntecedenteAlCatalogo $catalogo,
        GuardarAntecedentes $guardar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable` y no `required`, igual que el alta de motivos: el texto vacío no es un error de
            // formulario, es la regla de negocio de la Action y el mensaje tiene que ser el suyo.
            'descripcion' => 'nullable|string|max:200',
            'tipo' => 'nullable|string|max:2',
        ]);

        $destino = route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]);

        try {            [$antecedente, $creado] = $catalogo->ejecutar(
                $contexto['medico'],
                (string) ($datos['descripcion'] ?? ''),
                (string) ($datos['tipo'] ?? ''),
            );
        } catch (InvalidArgumentException $e) {
            // El diálogo se vuelve a abrir para que lo escrito se pueda corregir (guía de UI §3: ante un
            // error no se borra lo escrito).
            return redirect()->to($destino)
                ->withInput()
                ->withErrors(['descripcion' => $e->getMessage()])
                ->with('crear_antecedente', true);
        }

        $resultado = $guardar->ejecutar(
            (string) $consulta->reg_medico,
            (string) $consulta->numhistoria,
            [],
            [$antecedente->codigo],
            [],
            null,
            'web',
            // La consulta desde la que se escribe, para el registro de movimientos (2026-10-20).
            (int) $consulta->id,
        );

        $mensaje = $resultado->agregados === []
            // El código ya estaba en el paciente: la Action no lo duplica. Pasa si el médico crea el
            // antecedente que ya tenía cargado (el buscador no lo ofrece, el diálogo sí puede).
            ? '«' . $antecedente->nombre . '» ' . ($creado ? 'quedó en tu catálogo' : 'ya estaba en tu catálogo')
                . '; el paciente ya lo tenía cargado.'
            : 'Creado «' . $antecedente->nombre . '» en tu catálogo y agregado a los antecedentes del paciente.';

        return redirect()->to($destino)->with('estado', $mensaje);
    }

    /**
     * El buscador en vivo del catálogo (`Accept: application/json`): filtra sin distinguir tildes ni
     * mayúsculas, un antecedente por nombre, y dice cuáles ya tiene el paciente.
     */
    public function buscar(Request $request, Consulta $consulta, AntecedentesDelPaciente $antecedentes)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));
        $tipo = trim((string) $request->query('tipo', ''));

        $delPaciente = $antecedentes->delPaciente($contexto['reg_medico'], (string) $consulta->numhistoria);
        $codigos = $delPaciente->pluck('codigo')->all();

        $resultados = $antecedentes->catalogo($contexto['reg_medico'], $buscar, $tipo)
            ->map(fn ($antecedente) => [
                'codigo' => (string) $antecedente->codigo,
                'descripcion' => (string) $antecedente->nombre,
                'tipo' => $antecedente->tipoNombre,
                'yaEsta' => in_array((string) $antecedente->codigo, $codigos, true),
            ])
            ->values();

        return response()->json([
            'antecedentes' => $resultados,
            'existe' => $buscar !== '' && $antecedentes->existeEnElCatalogo($contexto['reg_medico'], $buscar),
            'buscar' => $buscar,
        ]);
    }

    /**
     * Las reglas del formulario de guardado: sólo las filas.
     *
     * `filas` llega por **id de `antece_paciente`**. El detalle lleva el tope de la columna (`text`) para no
     * aceptar un cuerpo absurdo, y la alerta los dos valores del DataWindow del escritorio (`A` encendida,
     * `B` apagada).
     */
    private function reglas(): array
    {
        return [
            'filas' => 'nullable|array|max:200',
            'filas.*.detalle' => 'nullable|string|max:' . GuardarAntecedentes::LARGO_DETALLE,
            'filas.*.alerta' => 'nullable|in:A,B',
        ];
    }

    /**
     * La vuelta a la sección con el chip y la búsqueda que el médico estaba mirando (O-07: el agregado y la
     * baja conservan el filtro, que es lo que hace que la fila nueva se vea en el acto).
     *
     * @param  array<string,mixed>  $datos  el `tipo` y el `buscar` del formulario
     */
    private function destino(Consulta $consulta, array $datos): \Illuminate\Http\RedirectResponse
    {
        $parametros = [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES];

        if (trim((string) ($datos['tipo'] ?? '')) !== '') {
            $parametros['tipo'] = trim((string) $datos['tipo']);
        }
        if (trim((string) ($datos['buscar'] ?? '')) !== '') {
            $parametros['buscar'] = trim((string) $datos['buscar']);
        }

        return redirect()->route('clinica.consulta', $parametros);
    }

    /**
     * La vuelta a la sección con el mensaje de lo que pasó. El orden de los avisos importa: si el sync
     * bloqueó algo, se dice eso primero (no se puede tapar con un "Guardado" que no fue).
     */
    private function volver(Consulta $consulta, ResultadoDeAntecedentes $resultado)
    {
        $destino = $this->destino($consulta, []);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a cargar los antecedentes y guárdalos de nuevo.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'Los antecedentes no tenían cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** "Agregados 2, quitado 1 y guardados 3": lo que pasó, con sus cuentas, no un "Operación exitosa". */
    private function resumen(ResultadoDeAntecedentes $resultado): string
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

        return 'Antecedentes actualizados: ' . implode(', ', $partes) . '.';
    }

    /**
     * "«Hipertensión»", "«Hipertensión» y «Asma»", "«A», «B» y «C»": nombra lo que pasó con cada
     * antecedente, no con su id.
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
