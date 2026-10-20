<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\AsignarHistoria;
use App\Actions\Pacientes\GuardarFamilia;
use App\Actions\Pacientes\GuardarPaciente;
use App\Actions\Pacientes\GuardarRepresentante;
use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\ContextoTrabajo;
use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\AtencionInmediata;
use App\Clinica\Consulta\PacienteDeLaCita;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\CatalogoDeParentescos;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Pacientes\FiltroDePacientes;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\RepresentantePorCedula;
use App\Clinica\Pacientes\Vistas\FamiliaDePacienteVista;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Clinica\Pacientes\Vistas\ListadoDePacientesVista;
use App\Clinica\Pacientes\Vistas\RepresentanteDeAltaVista;
use App\Http\Controllers\Controller;
use App\Models\Historia;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Pacientes del `reg_medico` del contexto: listado con búsqueda, ficha y —desde WEB-3.2— **alta y
 * edición de la ficha**.
 *
 * Es la pantalla de referencia de la estructura de la web clínica (Docs/Web/guia-arquitectura-web.md): el
 * controlador solo traduce HTTP, `PacientesDelConsultorio` lee, `GuardarPaciente` escribe y los ViewModels
 * de `app/Clinica/Pacientes/Vistas` arman lo que la vista pinta.
 *
 * **Quién puede dar de alta y editar** (decisión de WEB-3.2). El legado resuelve esto con el **Nivel 3**
 * (`Docs/Wiki/01-roles-y-usuarios.md`): la secretaría **no ve la ficha clínica**, pero sí administra los
 * datos del paciente. La pantalla que la web reemplaza —"Nuevo Paciente"/"Mantenimiento de pacientes" del
 * escritorio, `w_agregar_pacientes.srw`— es justamente la que usaba la secretaría para recibir a alguien
 * que llega sin cita, y es la misma regla que ya sigue la agenda: el alta de la cita (WEB-2.2) **ya crea
 * pacientes** desde la web con la cuenta de secretaría. Por eso el alta y la edición de los **datos de
 * contacto y filiación** quedan para médico **y** secretaría (el grupo de rutas ya es
 * `role:Root|Medico|Secretaria`), y lo que la secretaría sigue sin ver es la **historia clínica**
 * (consultas, antecedentes, documentos), que no se toca acá. La deuda de recortar el módulo Pacientes por
 * rol está anotada en `ROADMAP.md` y **no** es de esta tarea.
 *
 * **La misma vara para asignar la historia (WEB-3.3)**: el legado lo hacía la **secretaría al recibir al
 * paciente** —dice la wiki: "lo normal es que lo haga la secretaria al recibirlo"— y, si el paciente
 * llegaba a "Atender" sin historia, el **médico** en ese momento. Asignar el número es de las dos
 * cuentas; lo que la secretaría sigue sin ver es la consulta. Por eso la ruta vive en este controlador,
 * con el `role:Root|Medico|Secretaria` del grupo, y **no** se agrega ningún permiso nuevo.
 */
class PacienteController extends Controller
{
    /**
     * Los campos del **representante del alta de pediatría**, con las claves que usan `GuardarRepresentante` y
     * `RepresentantePorCedula`. En el formulario viajan con el prefijo `representante_` para no pisar los del niño.
     *
     * @var array<int,string>
     */
    private const CAMPOS_DEL_REPRESENTANTE = ['nombre', 'codeparentesco', 'cedula', 'telefono', 'email', 'direccion'];

    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private CatalogoDeSeguros $seguros,
        private FamiliaDelPaciente $familias,
        private RepresentantePorCedula $representantesPorCedula,
    ) {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $buscar = trim((string) $request->query('buscar', ''));
        // Un `?historia=` que no sea uno de los valores válidos vuelve a "Todos" en vez de mostrar una
        // lista mezclada: la pantalla no le hace caso a lo que no entiende.
        $filtro = FiltroDePacientes::normalizar($request->query('historia'));

        // **La lista global del doctor** (Fase 2 del cambio de `reg_medico`): todos sus registros —el suyo y
        // los centros donde trabaja—, y el número de historia del consultorio del contexto. La secretaría
        // recibe sólo el registro del centro, porque el `medico` de su contexto es la fila del centro.
        $registros = $this->registrosDelContexto($request);

        $listado = $this->pacientes->listar($registros, $contexto['reg_medico'], $buscar, $filtro);

        // Una página que ya no existe (`?page=999`, o se borró gente) mostraba la tabla vacía y un
        // "Mostrando – de 1033": se lleva a la **última**, con la búsqueda y el filtro que traía. Sin
        // resultados no hay última página a la que ir (`total() === 0`): se muestra el vacío, sin bucle.
        if ($listado->isEmpty() && $listado->total() > 0 && $listado->currentPage() > $listado->lastPage()) {
            return redirect()->route('clinica.pacientes', array_merge(
                $request->query(),
                ['page' => $listado->lastPage()],
            ));
        }

        return view('clinica.pacientes.index', [
            'vm' => new ListadoDePacientesVista(
                $listado,
                $buscar,
                $filtro,
                $this->pacientes->conteos($registros, $contexto['reg_medico'], $buscar),
                // La URL base la resuelve el controlador: el ViewModel no llama a `route()` (se prueba
                // sin contenedor) y el segmentado arma sus enlaces sobre esto.
                route('clinica.pacientes'),
            ),
        ]);
    }

    /** JSON para el buscador de "Nueva cita": pocos pacientes del contexto que coinciden con `q`. */
    public function buscar(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return response()->json($this->pacientes->sugerencias(
            $this->registrosDelContexto($request),
            trim((string) $request->query('q', '')),
            10,
            $contexto['reg_medico'],
        ));
    }

    /**
     * **El representante por cédula** del alta de pediatría (2026-10-19: un representante puede tener varios hijos).
     *
     * Devuelve los datos del adulto —nombre, parentesco, teléfono, correo y dirección— más los pacientes del consultorio de los
     * que ya es representante, con su enlace a la ficha. La búsqueda es explícita: lo que el formulario rellena se puede
     * corregir antes de guardar, y **el servidor vuelve a resolver la cédula al guardar** (no se confía en un campo oculto).
     *
     * Los datos y los hijos salen de `RepresentantePorCedula`, que sólo mira el `reg_medico` del contexto: la misma cédula de
     * otro consultorio no se mezcla. Una cédula que no está en ninguna fuente responde `{encontrado:false, hijos:[]}` con
     * **200**: "no está" es una respuesta válida —el adulto se puede cargar como nuevo—, no un error.
     */
    public function buscarRepresentante(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $datos = $request->validate([
            'cedula' => ['nullable', 'regex:/^[0-9]{1,10}$/'],
        ], [
            'cedula.regex' => 'La cédula solo lleva números (hasta 10 dígitos).',
        ]);

        $representante = $this->representantesPorCedula->buscar($regMedico, $datos['cedula'] ?? null);

        if ($representante === null) {
            return response()->json(['encontrado' => false, 'hijos' => []]);
        }

        return response()->json([
            'encontrado'   => true,
            'nombre'       => $representante['nombre'],
            // El código del catálogo (`M` Madre, `P` Padre…): es el `value` de las opciones del `<select>`.
            'parentesco'   => $representante['codeparentesco'],
            'telefono'     => $representante['telefono'],
            'email'        => $representante['email'],
            'direccion'    => $representante['direccion'],
            'hijos'        => array_map(
                fn (array $hijo) => [
                    'nombre'   => $hijo['nombre'],
                    'historia' => $hijo['historia'],
                    'url'      => route('clinica.pacientes.ver', $hijo['paciente_id']),
                ],
                $representante['hijos'],
            ),
        ]);
    }

    public function ver(Request $request, int $paciente, RepresentanteController $representante, PacienteDeLaCita $pacienteDeLaCita)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelContexto($request);

        // La ficha abre para un paciente visible en **cualquier** registro del doctor (Fase 2 del cambio de
        // `reg_medico`): el número que muestra —y el que decide si hay algo que asignar— es el del
        // consultorio del **contexto**, porque la consulta es del contexto.
        $ficha = $this->pacientes->ficha($registros, $paciente, $contexto['reg_medico']);

        // Un paciente de otro médico no se muestra ni se confirma que exista.
        abort_unless($ficha, 404);

        $vm = new FichaDePacienteVista($ficha, $this->pacientes->consultasDe($contexto['reg_medico'], $ficha->numhistoria));

        // El seguro se muestra por su **nombre** (el catálogo es del consultorio); un código que no esté
        // en él se muestra tal cual, y `00` (PERSONAL) como "sin seguro": lo decide el ViewModel.
        $vm = $vm->conSeguro($this->seguros->nombreDe($this->seguros->de($contexto['reg_medico']), $vm->codigoDelSeguro()));

        // La URL de la acción que llena la historia la arma el controlador (el ViewModel se prueba sin
        // contenedor): la ficha la usa si el paciente todavía no tiene número.
        $vm = $vm->conUrlAsignarHistoria(route('clinica.pacientes.asignar-historia', $ficha->id));

        // **«Ver otra historia»** (Fase 2): los consultorios del doctor donde el paciente **sí** tiene
        // historia, con su número. El del contexto primero; el resto se ofrece como enlace de sólo lectura a
        // la historia de ese consultorio (`?reg_medico=`). La secretaría recibe uno solo (el del centro), así
        // que no ve ningún selector.
        $vm = $vm
            ->conOtrasHistorias($this->pacientes->registrosConHistoria($registros, $paciente, $contexto['reg_medico']))
            ->conUrlOtraHistoria(fn (string $regMedico) => route('clinica.pacientes.historia', [
                'paciente'   => $paciente,
                'reg_medico' => $regMedico,
            ]));

        // ¿La historia **del consultorio** existe? El número del vínculo puede estar sin su fila en
        // `historias` (trampa 143): en ese caso la ficha muestra el número pero ofrece **registrarla**,
        // porque atender es justamente lo que no se puede. Se resuelve con el mismo lector que «Atender»
        // (`PacienteDeLaCita`), para que la ficha y el botón no puedan dar respuestas distintas. Se mira
        // **sólo el consultorio del contexto**: la historia de otro de los registros del doctor no es ésta.
        $historia = $pacienteDeLaCita->historia((int) $ficha->id, [$contexto['reg_medico']]);
        $vm = $vm->conHistoriaRegistrada($historia !== null);

        // **Cada consulta de la lista abre su pantalla** (2026-10-20): la ficha mostraba la línea de tiempo
        // pero no llevaba a la consulta; la historia del paciente (WEB-3.5) sí. Se ofrece con la misma regla
        // que ahí —la consulta es del médico, la secretaría recibe 403—, así que sin `trabajaLaConsulta` la
        // fila queda como texto.
        if ($request->user()?->trabajaLaConsulta()) {
            $vm = $vm->conUrlConsulta(fn (int $consulta) => route('clinica.consulta', $consulta));
        }

        // «¿Atender ahora?» (O-05): la ficha lo ofrece **sólo en el momento en que el paciente quedó
        // atendible** —lo deja dicho el flash de la acción que le puso el número—, sólo si la historia está
        // registrada y sólo a quien trabaja la consulta: atender es del médico (WEB-4.9). Un **centro** no
        // atiende, ni siquiera si algún día tiene login (`es_centro`).
        if ($this->ofreceAtender($request, $historia !== null, (int) $ficha->id)) {
            $vm = $vm->conUrlAtender(route('clinica.pacientes.atender', $ficha->id));
        }

        // El representante es **de la ficha** (WEB-3.4): no tiene pantalla propia, así que su bloque —con
        // su catálogo de parentescos ya leído y su URL de guardado— lo arma su controlador y la ficha lo
        // incluye, igual que el resto de los datos de la pantalla.
        return view('clinica.pacientes.ver', [
            'vm'            => $vm,
            'representante' => $representante->bloque($request, $paciente),
            // Las gráficas de crecimiento del niño (WEB-7.4.3c): sólo en pediatría y sólo para quien trabaja la consulta (la secretaría no ve lo clínico).
            'urlCrecimiento' => $request->user()?->trabajaLaConsulta()
                && str_contains((string) ($request->attributes->get('contexto_clinico')['specialty']?->slug ?? ''), 'pediatr')
                ? route('clinica.pacientes.crecimiento', $ficha->id)
                : null,
            'urlHistoriaPediatrica' => $request->user()?->trabajaLaConsulta()
                && str_contains((string) ($request->attributes->get('contexto_clinico')['specialty']?->slug ?? ''), 'pediatr')
                ? route('clinica.pacientes.historia-pediatrica', $ficha->id)
                : null,
            'urlVacunas' => $request->user()?->trabajaLaConsulta()
                && str_contains((string) ($request->attributes->get('contexto_clinico')['specialty']?->slug ?? ''), 'pediatr')
                ? route('clinica.pacientes.vacunas', $ficha->id)
                : null,
            // La familia del niño del escritorio de pediatría (WEB-7.4.2a): en cualquier otro paciente el bloque no existe.
            'familia'       => new FamiliaDePacienteVista(
                $this->familias->de($contexto['reg_medico'], $ficha->numhistoria),
                fn (int $id) => route('clinica.pacientes.ver', $id),
                // Sin historia no hay clave con la que guardar: no se ofrece el formulario (misma decisión que el representante).
                $ficha->numhistoria === null ? null : route('clinica.pacientes.familia', $ficha->id),
                str_contains((string) ($request->attributes->get('contexto_clinico')['specialty']?->slug ?? ''), 'pediatr'),
                // «Agregar un hermano» (WEB-7.4.2d): el alta de otro niño que nace ya dentro de esta familia.
                route('clinica.pacientes.nuevo', ['hermano_de' => $ficha->id]),
            ),
        ]);
    }

    /**
     * **Asignarle el número de historia** a un paciente que todavía no lo tiene (WEB-3.3).
     *
     * Es la mitad que faltaba de F3: la ficha del paciente ya se lee y se edita (WEB-3.1/3.2), pero el
     * número sólo se podía asignar desde la agenda, con "Pasar a cita formal" (WEB-2.11) y **sólo si el
     * paciente tenía una cita del día anclada**. La regla no se escribe de nuevo: la Action compartida
     * `App\Actions\Pacientes\AsignarHistoria` es la única que sabe el correlativo del médico —la última
     * de `historias` más las del pivote, comparada como entero—, el reintento ante la unicidad
     * `(reg_medico, numhistoria)`, la idempotencia y el enlace de las citas que estaban "sin historia".
     * La usan, además de esta pantalla, "Pasar a cita formal" y el sync del móvil.
     *
     * Cuatro decisiones que son de esta pantalla y no de la Action:
     *
     *  - **De quién es el paciente lo dice el vínculo** (`medico_pacientes.numhistoria`): si no está en
     *    el consultorio del contexto, **404** sin confirmar que exista, igual que su ficha.
     *  - **El `reg_medico` no viaja en el POST.** La Action lo saca del vínculo del paciente ("es el
     *    tenant, y una superficie no lo elige"), así que un `reg_medico` mandado a mano se ignora.
     *  - **Idempotente y visible**: si ya la tenía, la Action devuelve la suya y el mensaje lo dice con
     *    el número; si no, se informa **qué número** le tocó. Nunca se numera dos veces.
     *  - **Un número sin historia se registra, no se reemplaza** (trampa 143): cuando el vínculo ya dice
     *    «H. 7001» pero la fila de `historias` falta, la Action crea esa fila con el 7001 y el mensaje lo
     *    dice así. Es la salida del paciente que la ficha mostraba con número y no se podía atender.
     *  - **Los `reg_medico` del médico se resuelven con el mismo trait que "Pasar a cita formal"**, que a
     *    su vez copia al sync: el correlativo tiene que dar igual en las dos superficies.
     */
    public function asignarHistoria(Request $request, int $paciente, AsignarHistoria $accion, PacienteDeLaCita $pacienteDeLaCita)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // El paciente tiene que ser visible en alguno de los registros del doctor (o en el centro de la
        // secretaría): si no, 404 sin confirmar que exista.
        abort_unless($this->pacientes->ficha($this->registrosDelContexto($request), $paciente, $contexto['reg_medico']) !== null, 404);

        // **El paciente puede venir de otro consultorio del doctor** (Fase 2 del cambio de `reg_medico`): en
        // éste todavía no tiene vínculo, y la historia que se le va a asignar es la de **este** consultorio,
        // así que el vínculo se crea acá. Es idempotente: si ya lo tenía, no lo toca.
        $this->pacientes->vincular($contexto['reg_medico'], $paciente, (int) $contexto['medico']->id);

        $vinculo = $this->pacientes->vinculo($contexto['reg_medico'], $paciente);

        abort_unless($vinculo !== null, 404);

        // "Ya la tenía" es que la **historia del consultorio** exista, no que el vínculo muestre un número:
        // si el número está sin su fila en `historias` (trampa 143), la Action se la registra con ese mismo
        // número y el mensaje tiene que decir eso, no "ya tenía". Se mira **sólo el consultorio del
        // contexto**: la historia de otro de sus registros no es ésta.
        $yaTenia = $pacienteDeLaCita->historia((int) $vinculo->paciente_id, [$contexto['reg_medico']]) !== null;

        try {
            // El número es **del consultorio del contexto** (el tenant del vínculo), no de todos los
            // registros del médico: el paciente de otro consultorio del mismo doctor es otra historia.
            $historia = $accion->ejecutar($contexto['medico'], $contexto['reg_medico'], (int) $vinculo->paciente_id);
        } catch (InvalidArgumentException $excepcion) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', $excepcion->getMessage());
        }

        $mensaje = $yaTenia
            ? 'Este paciente ya tenía historia: H. ' . $historia->numhistoria . '. No se le asignó otra.'
            : (CamposDePaciente::historiaNumerica($vinculo->numhistoria) !== null
                ? 'Se registró la historia H. ' . $historia->numhistoria . ', que es el número que el paciente ya tenía, y ya se lo puede atender.'
                : 'Se le asignó la historia H. ' . $historia->numhistoria . ' y ya se lo puede atender.');

        return redirect()
            ->route('clinica.pacientes.ver', $paciente)
            ->with('estado', $mensaje)
            // O-05: el paciente acaba de quedar atendible, así que la ficha ofrece atenderlo.
            ->with('ofrecer_atender', $paciente);
    }

    /**
     * **Atender ahora al paciente que acaba de quedar atendible** (O-05, 2026-10-17).
     *
     * Es el «sí» del diálogo «¿Atender ahora?» que la ficha muestra después de asignarle la historia o
     * después del alta web (O-04). La regla vive en `AtencionInmediata`: si el paciente tiene una cita hoy
     * sin atender se abre esa; si no, se le crea la cita del día con la sede del contexto y se abre la
     * consulta. Acá sólo se resuelve de quién es el paciente y qué historia tiene, y se traduce el
     * resultado a una redirección.
     *
     * **Es del médico**: abrir la consulta es clínica y la secretaría recibe 403 —la misma regla de
     * WEB-4.9—. El botón no se le ofrece, y la ruta tampoco se le abre si la pide a mano.
     */
    public function atender(Request $request, int $paciente, AtencionInmediata $atencion, PacienteDeLaCita $pacienteDeLaCita)
    {
        // Atender es clínico y es del médico (WEB-4.9) —y un **centro** no atiende, ni siquiera si algún día
        // tiene login: la secretaría ya lo cubre por rol, esto es el seguro de `es_centro`.
        abort_unless((bool) $request->user()?->trabajaLaConsulta(), 403);
        abort_if((bool) ($request->attributes->get('contexto_clinico')['es_centro'] ?? false), 403);

        $contexto = $request->attributes->get('contexto_clinico');

        // Un paciente visible en otro de los registros del doctor se puede atender acá, pero **la historia es
        // la del consultorio del contexto**: si en éste no tiene número, no hay nada que atender.
        abort_unless($this->pacientes->ficha($this->registrosDelContexto($request), $paciente, $contexto['reg_medico']) !== null, 404);

        $vinculo = $this->pacientes->vinculo($contexto['reg_medico'], $paciente);

        // La historia se resuelve igual que al abrir la consulta (por el pivote del paciente en **este**
        // consultorio): si no la tiene, no hay nada que atender y se lo manda a la ficha, que es donde vive
        // la acción.
        $historia = $vinculo !== null
            ? $pacienteDeLaCita->historia((int) $vinculo->paciente_id, [$contexto['reg_medico']])
            : null;

        if ($historia === null) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', 'Este paciente todavía no tiene número de historia: asígnaselo antes de atenderlo.');
        }

        $resultado = $atencion->ejecutar($contexto, $this->registrosDelContexto($request), $historia);

        if (! $resultado->abrio()) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', $resultado->motivo());
        }

        // Al entrar a la consulta las alertas del paciente se avisan de nuevo (WEB-11.6), como en «Atender».
        session()->put('alertas_vistas', array_values(array_diff((array) session('alertas_vistas', []), [$resultado->consultaId()])));

        return redirect()
            ->route('clinica.consulta', $resultado->consultaId())
            ->with('estado', $resultado->mensaje());
    }

    /**
     * ¿La ficha tiene que ofrecer «¿Atender ahora?»? (O-05)
     *
     * Tres condiciones, y las tres importan: el paciente **acaba** de quedar atendible (lo dice el flash que
     * deja la acción que le puso el número, con su id, para que no se ofrezca en la ficha de otro), **tiene
     * la historia registrada** (no alcanza con el número del vínculo: sin la fila de `historias` la consulta
     * no se puede abrir, trampa 143) y quien mira **trabaja la consulta** (atender es del médico,
     * WEB-4.9). Fuera de ese momento la ficha no ofrece atender: no es una acción de la ficha, es el paso
     * que sigue al alta.
     */
    private function ofreceAtender(Request $request, bool $historiaRegistrada, int $pacienteId): bool
    {
        return (int) session('ofrecer_atender') === $pacienteId
            && $historiaRegistrada
            && (bool) $request->user()?->trabajaLaConsulta();
    }

    /**
     * El formulario de **alta** de paciente (`/clinica/pacientes/nuevo`).
     *
     * Los campos son los del alta del legado que tienen columna en `pacientes`. Quedan afuera, a propósito:
     *
     *  - **Foto** (`foto_pac`): el proyecto todavía no define dónde se guardan los archivos clínicos
     *    (WEB-T.1/WEB-4.7); una ruta de disco del escritorio no sirve desde la web.
     *  - **Representante** (`representante`): desde 2026-10-19 el **alta de pediatría** lo pide en su propia
     *    tarjeta (`_representante-alta`), porque un representante puede tener varios hijos y el niño no debería
     *    quedar sin su adulto hasta que alguien abra la ficha. La ficha lo sigue cargando con su diálogo
     *    (WEB-3.4), y el alta de las demás especialidades no lo pide.
     *  - **`numhistoria`**: no es un dato del formulario. Desde O-04 (2026-10-17) el alta web **numera en
     *    el mismo paso**, con `AsignarHistoria` (WEB-3.3): el número lo calcula el servidor y el médico no
     *    lo elige.
     *  - **`password`/`user_id`**: el login del paciente no existe en AppDDR.
     *  - **`sms`** (el tilde de recordatorios del escritorio): la web manda recordatorios desde la agenda
     *    por cita (WEB-2.6) y no escribe esa columna (misma decisión que WEB-2.6/2.7).
     *  - **`medico`**: es la `clave` del médico **dentro de la instalación del escritorio**, no de la nube;
     *    el vínculo con el médico del contexto se escribe en `medico_pacientes` (lo hace la Action).
     */
    public function nuevo(Request $request)
    {
        // «Agregar un hermano» (WEB-7.4.2d): `?hermano_de=` precarga los apellidos y deja el rastro para unir las familias al guardar.
        // Un valor que no sea un niño del consultorio se ignora: el alta sigue siendo la de siempre.
        $hermano = $this->hermanoDe($request, (int) $request->query('hermano_de', 0));

        if ($hermano !== null) {
            $request->session()->now('_old_input', ['apellidos' => $hermano->apellidos, 'hermano_de' => $hermano->id]);
        }

        // «Agregar «texto»» de la búsqueda del listado: lo escrito (si trae letras) llega a `?nombre=` y se precarga en el primer
        // campo del nombre. Un `old()` de verdad (validación fallida) no pasa por acá: es una carga por GET.
        $buscado = mb_substr(preg_replace('/\s+/u', ' ', trim((string) $request->query('nombre', ''))), 0, 25);

        if ($hermano === null && $buscado !== '' && preg_match('/\p{L}/u', $buscado) === 1) {
            $request->session()->now('_old_input', ['apellidos' => $buscado]);
        }

        return $this->formulario($request, null, $hermano);
    }

    /**
     * El formulario de **edición** (`/clinica/pacientes/{paciente}/editar`). Un paciente de otro
     * `reg_medico` responde **404**, igual que su ficha: no se confirma que exista.
     */
    public function editar(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->editable($this->registrosDelContexto($request), $paciente, $contexto['reg_medico']);

        abort_unless($ficha !== null, 404);

        return $this->formulario($request, $ficha);
    }

    /** Alta o edición según venga o no el id. La regla —enlazar por cédula y no pisar datos— es la Action. */
    public function guardar(Request $request, GuardarPaciente $accion)
    {
        return $this->persistir($request, $accion, null);
    }

    /** Guardar la edición de la ficha propia. */
    public function actualizar(Request $request, int $paciente, GuardarPaciente $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->editable($this->registrosDelContexto($request), $paciente, $contexto['reg_medico']);

        abort_unless($ficha !== null, 404);

        return $this->persistir($request, $accion, $ficha);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @param  array{paciente:\App\Models\Paciente, numhistoria:?string}|null  $ficha */
    private function formulario(Request $request, ?array $ficha, ?object $hermano = null)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $vm = new FormularioDePacienteVista(
            $ficha['paciente'] ?? null,
            $ficha['numhistoria'] ?? null,
            $this->seguros->de($contexto['reg_medico']),
            // La especialidad del contexto decide los valores por defecto del alta (O-01: en
            // ginecología el sexo arranca en Femenino; una especialidad sin la clave, en «— Sin dato —»).
            $contexto['specialty'] ?? null,
        );

        // La tarjeta «Representante» del alta (2026-10-19): **sólo pediatría y sólo el alta**. En la edición el
        // representante se sigue cargando con el diálogo de la ficha (WEB-3.4), y fuera de pediatría el alta no
        // lo pide. La tarjeta se arma igual —inerte— para que la vista no tenga que mirar si el ViewModel
        // existe; el catálogo de parentescos y la URL del buscador se leen sólo cuando se ofrece, que es una
        // consulta que no hace falta en ginecología.
        $ofreceRepresentante = ! $vm->editando()
            && AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria();

        return view('clinica.pacientes.formulario', [
            'vm'                 => $vm,
            'hermano'            => $hermano,
            'representanteAlta'  => new RepresentanteDeAltaVista(
                $ofreceRepresentante,
                $ofreceRepresentante ? route('clinica.pacientes.representante.buscar') : null,
                $ofreceRepresentante ? app(CatalogoDeParentescos::class)->todos() : null,
            ),
        ]);
    }

    /**
     * El niño al que se le agrega un hermano: un paciente del consultorio con historia que ya es un `paciente_chamo` (si no, no hay
     * familia a la que unirse). `null` si no cumple: no se confirma nada sobre pacientes ajenos.
     */
    private function hermanoDe(Request $request, int $pacienteId): ?object
    {
        if ($pacienteId <= 0) {
            return null;
        }

        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->ficha($this->registrosDelContexto($request), $pacienteId, $contexto['reg_medico']);

        if ($ficha === null || $ficha->numhistoria === null || $this->familias->de($contexto['reg_medico'], $ficha->numhistoria) === null) {
            return null;
        }

        return $ficha;
    }

    /**
     * Los `reg_medico` que puede ver el usuario en esta pantalla: **todos los del doctor** (el suyo y los
     * centros donde trabaja) o, en la secretaría, sólo el del centro del contexto. La regla vive en
     * `ContextoTrabajo` y es la misma que usan la agenda y el `medico` del contexto.
     *
     * @return array<int,string>
     */
    private function registrosDelContexto(Request $request): array
    {
        return app(ContextoTrabajo::class)->registrosDelContexto($request->attributes->get('contexto_clinico'));
    }

    /** @param  array{paciente:\App\Models\Paciente, numhistoria:?string}|null  $ficha */
    private function persistir(Request $request, GuardarPaciente $accion, ?array $ficha)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $datos = $this->validar($request, $ficha['paciente'] ?? null);
        // Sólo el alta puede traer un hermano; en una edición el campo se ignora.
        $hermano = $ficha === null ? $this->hermanoDe($request, (int) ($datos['hermano_de'] ?? 0)) : null;
        // El representante del alta de pediatría (2026-10-19): se **valida acá**, antes de crear al paciente, para que un
        // correo mal escrito no deje una ficha a medio cargar. `null` = no vino nada, o no es pediatría, o es una edición.
        $representante = $ficha === null ? $this->validarRepresentanteDelAlta($request, $contexto) : null;
        $columnas = $this->columnas($datos);

        try {
            $paciente = $accion->ejecutar($columnas, $contexto['medico'], $contexto['reg_medico'], $ficha['paciente'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            // La Action rechaza la cédula que ya es de otro paciente (la misma regla del cambio de
            // cédula, WEB-2.15) y el nombre faltante: vuelve al formulario con lo escrito, sin perder
            // nada. El error va al campo que lo causó para que se lea debajo del control.
            $campo = str_contains($excepcion->getMessage(), 'édula') ? 'cedula' : 'nombres';

            throw ValidationException::withMessages([$campo => $excepcion->getMessage()]);
        }

        // **El alta desde la web numera la historia en el mismo paso** (O-04, 2026-10-17): el número lo
        // sigue dando `AsignarHistoria` —la única regla del correlativo, compartida con el sync y con
        // «Pasar a cita formal»—; lo que cambia es *cuándo* se pide. La edición de la ficha no numera.
        //
        // Es el alta **de la web** (la del médico, la de la secretaría y la rápida del paso 2 de «Nueva
        // cita»), no la del chatbot ni la del móvil: esas dos crean por `CrearPaciente` sin consultorio de
        // contexto y siguen sin número (el paciente online se numera cuando alguien lo recibe).
        $historia = $ficha === null
            ? app(AsignarHistoria::class)->ejecutar(
                $contexto['medico'],
                $contexto['reg_medico'],
                (int) $paciente->getKey(),
            )
            : null;

        $unido = $hermano !== null ? $this->unirComoHermano($contexto, $paciente, $hermano, $historia) : null;

        // El representante queda guardado para la historia nueva (misma regla que la ficha, `GuardarRepresentante`) y, si su
        // cédula es de una familia del escritorio, el niño nuevo se enlaza a esa familia y queda como hermano.
        $avisoRepresentante = $representante === null
            ? null
            : $this->guardarRepresentanteDelAlta($contexto, $paciente, $historia, $representante);

        $mensaje = $ficha !== null
            ? 'Se guardaron los cambios de la ficha.'
            : 'Paciente registrado y con historia H. ' . $historia->numhistoria . '. Ya se lo puede atender.';

        if ($hermano !== null) {
            $mensaje = $unido !== null
                ? 'Se registró a ' . $paciente->nombres . ' como hermano(a) de ' . $hermano->nombres . ': comparten la familia y el teléfono de contacto. '
                    . 'Le tocó la historia H. ' . $unido . '.'
                : 'Paciente registrado, pero no se pudo unir a la familia de ' . $hermano->nombres . '. Entra a su ficha y cárgale la familia.';
        }

        if ($avisoRepresentante !== null) {
            $mensaje .= ' ' . $avisoRepresentante;
        }

        // Editada desde la consulta (WEB-11.25): se vuelve a ella, a la sección de datos, y no a la ficha.
        $consulta = (int) $request->input('volver_a_consulta', 0);
        if ($ficha !== null && $consulta > 0) {
            return redirect()
                ->route('clinica.consulta', [$consulta, 'seccion' => \App\Clinica\Consulta\Vistas\ConsultaShellVista::SECCION_DATOS])
                ->with('estado', $mensaje);
        }

        return redirect()
            ->route('clinica.pacientes.ver', $paciente->id)
            ->with('estado', $mensaje)
            // O-05: el paciente acaba de quedar atendible. La ficha le ofrece «¿Atender ahora?» a quien
            // trabaja la consulta; «después» es cerrar el diálogo y quedarse en la ficha.
            ->with('ofrecer_atender', $ficha === null ? $paciente->id : null);
    }

    /**
     * Le asigna la historia al niño recién creado (la familia se guarda por ese número) y lo une a la familia de su hermano. Devuelve el
     * número de historia, o `null` si no se pudo unir. Las reglas son las Actions `AsignarHistoria` y `GuardarFamilia::comoHermano`.
     *
     * La historia **ya viene puesta por el alta** (O-04): acá no se numera de nuevo, se usa la que le tocó.
     */
    private function unirComoHermano(array $contexto, Paciente $paciente, object $hermano, ?Historia $historia): ?string
    {
        if ($historia === null) {
            return null;
        }

        try {
            $numero = (string) $historia->numhistoria;

            $familia = app(GuardarFamilia::class)->comoHermano($contexto['reg_medico'], $hermano->numhistoria, $numero, $paciente);

            return $familia === null ? null : $numero;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * La validación del **representante del alta de pediatría** (2026-10-19), o `null` si no hay nada que guardar.
     *
     * Las reglas son las del diálogo de la ficha (`RepresentanteController::guardar`), con los anchos de las columnas legadas:
     * nombre 40, parentesco 1 carácter, cédula hasta 10 dígitos, teléfono 20, correo 100 y dirección 60. El `codeparentesco`
     * **no** se restringe al catálogo a propósito: un código que subió el escritorio se conserva, no se rechaza (misma regla
     * que la ficha).
     *
     * Devuelve sólo las claves del representante y **sin el prefijo** (`representante_cedula` → `cedula`), que es lo que
     * esperan `GuardarRepresentante` y `RepresentantePorCedula`. Los campos vacíos vuelven como `null`: "no lo dijo" es un
     * valor del legado y no un texto en blanco.
     *
     * @return array{nombre:?string, codeparentesco:?string, cedula:?string, telefono:?string, email:?string, direccion:?string}|null
     */
    private function validarRepresentanteDelAlta(Request $request, array $contexto): ?array
    {
        // Fuera de pediatría el alta no pide representante: la tarjeta no se muestra y lo que llegue se ignora.
        if (! AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria()) {
            return null;
        }

        $request->validate([
            'representante_nombre'     => ['nullable', 'string', 'max:40'],
            'representante_parentesco' => ['nullable', 'string', 'max:1'],
            'representante_cedula'     => ['nullable', 'regex:/^[0-9]{1,10}$/'],
            'representante_telefono'   => ['nullable', 'string', 'max:20'],
            'representante_email'      => ['nullable', 'email', 'max:100'],
            'representante_direccion'  => ['nullable', 'string', 'max:60'],
        ], [
            'representante_nombre.max'        => 'El nombre del representante no puede pasar de 40 caracteres.',
            'representante_parentesco.max'    => 'El parentesco del representante es un solo carácter (es el ancho del escritorio).',
            'representante_cedula.regex'      => 'La cédula del representante solo lleva números (hasta 10 dígitos).',
            'representante_telefono.max'      => 'El teléfono del representante no puede pasar de 20 caracteres.',
            'representante_email.email'       => 'Revisa el correo del representante: no tiene forma de correo.',
            'representante_email.max'         => 'El correo del representante no puede pasar de 100 caracteres.',
            'representante_direccion.max'     => 'La dirección del representante no puede pasar de 60 caracteres (es el ancho del escritorio).',
        ]);

        $datos = [];

        foreach (self::CAMPOS_DEL_REPRESENTANTE as $campo) {
            $datos[$campo] = CamposDePaciente::texto($request->input('representante_' . $campo));
        }

        // El formulario vacío no es un representante: no crea una fila en blanco (la misma regla de la ficha).
        if (collect($datos)->every(fn ($valor) => $valor === null)) {
            return null;
        }

        return $datos;
    }

    /**
     * Guarda el **representante del alta** para la historia nueva y enlaza al niño a la familia del escritorio, si la cédula
     * pertenece a una.
     *
     * Tres decisiones del camino:
     *
     *  - **La cédula es el vínculo y el servidor la vuelve a resolver**: la familia no viaja en el formulario. `buscar()` la lee
     *    del consultorio del contexto, y si el representante es nuevo (o sólo está en el escritorio) completa los datos que el
     *    formulario no trajo. Si el usuario escribió algo, eso manda: la búsqueda es una ayuda, no una verdad que pise lo escrito.
     *  - **El enlace a la familia** lo hace `GuardarFamilia::enlazarAFamilia`, idempotente: si el niño ya quedó unido por la vía
     *    del hermano, no se le cambia la familia ni se duplica su fila de `paciente_chamo`.
     *  - **Si el representante o la familia fallan, el paciente no se pierde**: el alta ya está hecha, así que se registra el
     *    problema en el log y se devuelve un aviso para el mensaje. Nunca un 500 —el paciente ya está creado y con historia—.
     *
     * @param  array{nombre:?string, codeparentesco:?string, cedula:?string, telefono:?string, email:?string, direccion:?string}  $datos
     * @return string|null  el aviso para agregar al mensaje del alta, o `null` si no hubo nada que avisar
     */
    private function guardarRepresentanteDelAlta(array $contexto, Paciente $paciente, ?Historia $historia, array $datos): ?string
    {
        if ($historia === null) {
            return null;
        }

        $regMedico = $contexto['reg_medico'];
        $numero = (string) $historia->numhistoria;
        $familia = null;

        if ($datos['cedula'] !== null) {
            $encontrado = $this->representantesPorCedula->buscar($regMedico, $datos['cedula']);
            $familia = $encontrado['familia'] ?? null;
            $datos = $this->completarRepresentante($datos, $encontrado);
        }

        $problemas = [];

        if ($familia !== null) {
            try {
                app(GuardarFamilia::class)->enlazarAFamilia($regMedico, $familia, $numero, $paciente);
            } catch (Throwable $excepcion) {
                Log::warning('Alta de paciente: no se pudo enlazar a la familia del representante', [
                    'reg_medico' => $regMedico, 'paciente' => $paciente->getKey(), 'familia' => $familia,
                    'error' => $excepcion->getMessage(),
                ]);
                $problemas[] = 'la familia del representante';
            }
        }

        try {
            app(GuardarRepresentante::class)->ejecutar($regMedico, $numero, $datos);
        } catch (Throwable $excepcion) {
            Log::warning('Alta de paciente: no se pudo guardar el representante', [
                'reg_medico' => $regMedico, 'paciente' => $paciente->getKey(),
                'error' => $excepcion->getMessage(),
            ]);
            $problemas[] = 'el representante';
        }

        if ($problemas === []) {
            return 'Se guardó el representante del paciente.';
        }

        return 'Se registró el paciente, pero no se pudo guardar ' . implode(' ni ', $problemas)
            . ': entra a su ficha y cárgalo desde ahí.';
    }

    /**
     * Si el formulario trajo **sólo la cédula**, el resto de los datos del representante se completan con lo que ya está
     * cargado en el consultorio (lo de otro hijo del mismo adulto). Si el usuario escribió algo, no se pisa: sus datos mandan.
     *
     * @param  array<string,?string>  $datos
     * @param  array{nombre:?string, codeparentesco:?string, telefono:?string, email:?string, direccion:?string, familia:?int, hijos:array<int,mixed>}|null  $encontrado
     * @return array<string,?string>
     */
    private function completarRepresentante(array $datos, ?array $encontrado): array
    {
        $soloLaCedula = collect($datos)
            ->except('cedula')
            ->every(fn ($valor) => $valor === null);

        if (! $soloLaCedula || $encontrado === null) {
            return $datos;
        }

        foreach (self::CAMPOS_DEL_REPRESENTANTE as $campo) {
            if ($campo !== 'cedula') {
                $datos[$campo] = $encontrado[$campo] ?? null;
            }
        }

        return $datos;
    }

    /** @return array<string,mixed> */
    private function validar(Request $request, ?Paciente $paciente = null): array
    {
        // La cédula es obligatoria **salvo** que el paciente vaya "sin cédula" (la opción "S.C." del
        // legado, wiki `04-historia-clinica.md`): son los tres únicos obligatorios del alta —nombre,
        // apellido y cédula—, y `S.C.` es la forma de decir que no hay cédula.
        //
        // El caso que obliga a mirar también **cómo está el paciente hoy**: editar a alguien que ya está
        // sin cédula y guardar cualquier otro dato (sin tocar la cédula, que el navegador ni manda porque
        // el campo está vacío) no puede quedar rechazado por un dato que el paciente no tiene.
        // En pediatría no hay cédula del paciente (WEB-7.4.2d): el niño se identifica por nombre, nacimiento y sexo, y la cédula que
        // importa es la del representante. Un niño con cédula (los más grandes) la puede escribir igual.
        $sinCedula = $request->boolean('sin_cedula')
            || str_contains((string) ($request->attributes->get('contexto_clinico')['specialty']?->slug ?? ''), 'pediatr')
            || ($paciente !== null && IdentidadDePaciente::esSinCedula($paciente->cedula));

        return $request->validate([
            'nac'          => ['nullable', 'in:' . implode(',', array_keys(FormularioDePacienteVista::NACIONALIDADES))],
            'sin_cedula'   => ['nullable', 'boolean'],
            'cedula'       => [$sinCedula ? 'nullable' : 'required', 'regex:/^[0-9]{1,10}$/'],
            'apellidos'    => ['required', 'string', 'max:25'],
            'nombres'      => ['required', 'string', 'max:25'],
            'sexo'         => ['nullable', 'in:' . implode(',', array_keys(FormularioDePacienteVista::SEXOS))],
            // Una fecha de nacimiento futura no es un dato posible: se guardaba y dejaba la ficha sin fecha ni edad.
            'fnacimiento'  => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'lnacimiento'  => ['nullable', 'string', 'max:100'],
            'codeestado'   => ['nullable', 'in:' . implode(',', array_keys(FormularioDePacienteVista::ESTADOS_CIVILES))],
            'direccion'    => ['nullable', 'string', 'max:200'],
            'telefono'     => $this->reglasDelTelefono($request, $paciente),
            'email'        => ['nullable', 'email', 'max:100'],
            'profesion'    => ['nullable', 'string', 'max:50'],
            'ocupacion'    => ['nullable', 'string', 'max:100'],
            'escolaridad'  => ['nullable', 'string', 'max:100'],
            'codesegemp'   => ['nullable', 'string', 'max:3'],
            'dependencia'  => ['nullable', 'string', 'max:30'],
            'fingreso'     => ['nullable', 'date_format:Y-m-d'],
            'volver_a_consulta' => ['nullable', 'integer'],
            'hermano_de'   => ['nullable', 'integer'],
        ], $this->avisos());
    }

    /**
     * El teléfono: solo dígitos y los signos de siempre (`+ - ( ) .` y espacios), con al menos un dígito.
     * Se comprueba **solo si cambió**: el legado trae teléfonos escritos de cualquier manera ("llamar a la
     * tía") y editar otro dato de ese paciente no puede quedar bloqueado por algo que no se tocó (la misma
     * idea que con la cédula "S.C.").
     *
     * @return array<int,string>
     */
    private function reglasDelTelefono(Request $request, ?Paciente $paciente): array
    {
        $reglas = ['nullable', 'string', 'max:20'];

        if (trim((string) $request->input('telefono')) !== trim((string) ($paciente?->telefono ?? ''))) {
            $reglas[] = 'regex:/^(?=.*[0-9])[0-9+\-\s().]+$/';
        }

        return $reglas;
    }

    /**
     * Los avisos de la ficha, **uno por regla**: el `locale` de la app es `en` (la API comparte esa
     * configuración y no se toca por una pantalla), así que una regla sin texto propio sale en inglés.
     * `PacientesAltaEdicionWebTest::test_todos_los_errores_de_la_ficha_estan_en_espanol` lo exige.
     *
     * @return array<string,string>
     */
    private function avisos(): array
    {
        return [
            'nac.in'                  => 'La nacionalidad elegida no es válida.',
            'sin_cedula.boolean'      => 'La opción "Sin cédula" no es válida.',
            'cedula.required'         => 'Escribe la cédula o marca "Sin cédula (S.C.)".',
            'cedula.regex'            => 'La cédula solo lleva números (hasta 10 dígitos).',
            'apellidos.required'      => 'El apellido es obligatorio.',
            'apellidos.max'           => 'El apellido no puede pasar de 25 caracteres.',
            'nombres.required'        => 'El nombre es obligatorio.',
            'nombres.max'             => 'El nombre no puede pasar de 25 caracteres.',
            'sexo.in'                 => 'El sexo elegido no es válido.',
            'fnacimiento.date_format' => 'La fecha de nacimiento va como día/mes/año.',
            'fnacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser posterior a hoy.',
            'lnacimiento.max'         => 'El lugar de nacimiento no puede pasar de 100 caracteres.',
            'codeestado.in'           => 'El estado civil elegido no es válido.',
            'direccion.max'           => 'La dirección no puede pasar de 200 caracteres.',
            'telefono.max'            => 'El teléfono no puede pasar de 20 caracteres.',
            'telefono.regex'          => 'El teléfono solo lleva números, espacios y los signos + - ( ) .',
            'email.email'             => 'Revisa el correo: no tiene forma de correo.',
            'email.max'               => 'El correo no puede pasar de 100 caracteres.',
            'profesion.max'           => 'La profesión no puede pasar de 50 caracteres.',
            'ocupacion.max'           => 'La ocupación no puede pasar de 100 caracteres.',
            'escolaridad.max'         => 'La escolaridad no puede pasar de 100 caracteres.',
            'codesegemp.max'          => 'El seguro elegido no es válido.',
            'dependencia.max'         => 'El campo "Referido por" no puede pasar de 30 caracteres.',
            'fingreso.date_format'    => 'La fecha de ingreso va como día/mes/año.',
        ];
    }

    /**
     * Las columnas de `pacientes` que van a la Action. "Sin cédula" se guarda **nula** (no el texto
     * `S.C.`): es como el legado deja a los pacientes sin documento —los 6 de la base de desarrollo están
     * en `null`— y como los lee `CamposDePaciente`, así que no hay dos formas del mismo "no hay cédula".
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function columnas(array $datos): array
    {
        unset($datos['sin_cedula'], $datos['volver_a_consulta'], $datos['hermano_de']);

        if (trim((string) ($datos['cedula'] ?? '')) === '') {
            $datos['cedula'] = null;
        }

        return $datos;
    }
}
