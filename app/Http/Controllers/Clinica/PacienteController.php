<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\AsignarHistoria;
use App\Actions\Pacientes\GuardarPaciente;
use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Clinica\Pacientes\FiltroDePacientes;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Clinica\Pacientes\Vistas\ListadoDePacientesVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private CatalogoDeSeguros $seguros,
    ) {
    }

    public function index(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];
        $buscar = trim((string) $request->query('buscar', ''));
        // Un `?historia=` que no sea uno de los valores válidos vuelve a "Todos" en vez de mostrar una
        // lista mezclada: la pantalla no le hace caso a lo que no entiende.
        $filtro = FiltroDePacientes::normalizar($request->query('historia'));

        $listado = $this->pacientes->listar($regMedico, $buscar, $filtro);

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
                $this->pacientes->conteos($regMedico, $buscar),
                // La URL base la resuelve el controlador: el ViewModel no llama a `route()` (se prueba
                // sin contenedor) y el segmentado arma sus enlaces sobre esto.
                route('clinica.pacientes'),
            ),
        ]);
    }

    /** JSON para el buscador de "Nueva cita": pocos pacientes del contexto que coinciden con `q`. */
    public function buscar(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        return response()->json($this->pacientes->sugerencias($regMedico, trim((string) $request->query('q', ''))));
    }

    public function ver(Request $request, int $paciente, RepresentanteController $representante)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);

        // Un paciente de otro médico no se muestra ni se confirma que exista.
        abort_unless($ficha, 404);

        $vm = new FichaDePacienteVista($ficha, $this->pacientes->consultasDe($regMedico, $ficha->numhistoria));

        // El seguro se muestra por su **nombre** (el catálogo es del consultorio); un código que no esté
        // en él se muestra tal cual, y `00` (PERSONAL) como "sin seguro": lo decide el ViewModel.
        $vm = $vm->conSeguro($this->seguros->nombreDe($this->seguros->de($regMedico), $vm->codigoDelSeguro()));

        // La URL de la acción que llena la historia la arma el controlador (el ViewModel se prueba sin
        // contenedor): la ficha la usa sólo si el paciente todavía no tiene número.
        $vm = $vm->conUrlAsignarHistoria(route('clinica.pacientes.asignar-historia', $ficha->id));

        // El representante es **de la ficha** (WEB-3.4): no tiene pantalla propia, así que su bloque —con
        // su catálogo de parentescos ya leído y su URL de guardado— lo arma su controlador y la ficha lo
        // incluye, igual que el resto de los datos de la pantalla.
        return view('clinica.pacientes.ver', [
            'vm'            => $vm,
            'representante' => $representante->bloque($request, $paciente),
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
     *  - **Los `reg_medico` del médico se resuelven con el mismo trait que "Pasar a cita formal"**, que a
     *    su vez copia al sync: el correlativo tiene que dar igual en las dos superficies.
     */
    public function asignarHistoria(Request $request, int $paciente, AsignarHistoria $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $vinculo = $this->pacientes->vinculo($contexto['reg_medico'], $paciente);

        abort_unless($vinculo !== null, 404);

        $yaTenia = $vinculo->numhistoria !== null;

        try {
            $historia = $accion->ejecutar(
                $contexto['medico'],
                $this->registrosDelMedico($contexto['medico']),
                (int) $vinculo->paciente_id,
            );
        } catch (InvalidArgumentException $excepcion) {
            return redirect()
                ->route('clinica.pacientes.ver', $paciente)
                ->with('error', $excepcion->getMessage());
        }

        return redirect()
            ->route('clinica.pacientes.ver', $paciente)
            ->with('estado', $yaTenia
                ? 'Este paciente ya tenía historia: H. ' . $historia->numhistoria . '. No se le asignó otra.'
                : 'Se le asignó la historia H. ' . $historia->numhistoria . ' y ya se lo puede atender.');
    }

    /**
     * El formulario de **alta** de paciente (`/clinica/pacientes/nuevo`).
     *
     * Los campos son los del alta del legado que tienen columna en `pacientes`. Quedan afuera, a propósito:
     *
     *  - **Foto** (`foto_pac`): el proyecto todavía no define dónde se guardan los archivos clínicos
     *    (WEB-T.1/WEB-4.7); una ruta de disco del escritorio no sirve desde la web.
     *  - **Representante** (`representante`): es **WEB-3.4**, con sus columnas nullable nuevas.
     *  - **`numhistoria`**: no es un dato del formulario, lo asigna el servidor cuando la historia se
     *    completa (WEB-3.3, `AsignarHistoria`).
     *  - **`password`/`user_id`**: el login del paciente no existe en AppDDR.
     *  - **`sms`** (el tilde de recordatorios del escritorio): la web manda recordatorios desde la agenda
     *    por cita (WEB-2.6) y no escribe esa columna (misma decisión que WEB-2.6/2.7).
     *  - **`medico`**: es la `clave` del médico **dentro de la instalación del escritorio**, no de la nube;
     *    el vínculo con el médico del contexto se escribe en `medico_pacientes` (lo hace la Action).
     */
    public function nuevo(Request $request)
    {
        return $this->formulario($request, null);
    }

    /**
     * El formulario de **edición** (`/clinica/pacientes/{paciente}/editar`). Un paciente de otro
     * `reg_medico` responde **404**, igual que su ficha: no se confirma que exista.
     */
    public function editar(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->editable($contexto['reg_medico'], $paciente);

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
        $ficha = $this->pacientes->editable($contexto['reg_medico'], $paciente);

        abort_unless($ficha !== null, 404);

        return $this->persistir($request, $accion, $ficha);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @param  array{paciente:\App\Models\Paciente, numhistoria:?string}|null  $ficha */
    private function formulario(Request $request, ?array $ficha)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return view('clinica.pacientes.formulario', [
            'vm' => new FormularioDePacienteVista(
                $ficha['paciente'] ?? null,
                $ficha['numhistoria'] ?? null,
                $this->seguros->de($contexto['reg_medico']),
            ),
        ]);
    }

    /** @param  array{paciente:\App\Models\Paciente, numhistoria:?string}|null  $ficha */
    private function persistir(Request $request, GuardarPaciente $accion, ?array $ficha)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $datos = $this->validar($request, $ficha['paciente'] ?? null);
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

        $mensaje = $ficha !== null
            ? 'Se guardaron los cambios de la ficha.'
            : 'Paciente registrado. Todavía no tiene número de historia: se lo asigna el servidor cuando '
                . 'se complete la historia.';

        // "¿Atenderá a este paciente hoy?": la pregunta del alta del legado. El sí lleva al alta de cita
        // con el paciente ya elegido, donde la agenda pide la razón y arma la cola del día.
        if ($ficha === null && $request->boolean('atender_hoy')) {
            return redirect()
                ->route('clinica.agenda.nueva', ['fecha' => FechaDelConsultorio::hoy()->toDateString(), 'paciente_id' => $paciente->id])
                ->with('estado', $mensaje . ' Ahora elige la razón y la hora de la cita de hoy.');
        }

        return redirect()
            ->route('clinica.pacientes.ver', $paciente->id)
            ->with('estado', $mensaje);
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
        $sinCedula = $request->boolean('sin_cedula')
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
            'atender_hoy'  => ['nullable', 'boolean'],
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
            'atender_hoy.boolean'     => 'La opción "¿Atenderá hoy?" no es válida.',
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
        unset($datos['sin_cedula'], $datos['atender_hoy']);

        if (trim((string) ($datos['cedula'] ?? '')) === '') {
            $datos['cedula'] = null;
        }

        return $datos;
    }
}
