<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\GuardarPaciente;
use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Clinica\Pacientes\Vistas\ListadoDePacientesVista;
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
 */
class PacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private CatalogoDeSeguros $seguros,
    ) {
    }

    public function index(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];
        $buscar = trim((string) $request->query('buscar', ''));

        return view('clinica.pacientes.index', [
            'vm' => new ListadoDePacientesVista($this->pacientes->listar($regMedico, $buscar), $buscar),
        ]);
    }

    /** JSON para el buscador de "Nueva cita": pocos pacientes del contexto que coinciden con `q`. */
    public function buscar(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        return response()->json($this->pacientes->sugerencias($regMedico, trim((string) $request->query('q', ''))));
    }

    public function ver(Request $request, int $paciente)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);

        // Un paciente de otro médico no se muestra ni se confirma que exista.
        abort_unless($ficha, 404);

        $vm = new FichaDePacienteVista($ficha, $this->pacientes->consultasDe($regMedico, $ficha->numhistoria));

        // El seguro se muestra por su **nombre** (el catálogo es del consultorio); un código que no esté
        // en él se muestra tal cual, y `00` (PERSONAL) como "sin seguro": lo decide el ViewModel.
        $vm = $vm->conSeguro($this->seguros->nombreDe($this->seguros->de($regMedico), $vm->codigoDelSeguro()));

        return view('clinica.pacientes.ver', ['vm' => $vm]);
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
                ->route('clinica.agenda.nueva', ['fecha' => now()->toDateString(), 'paciente_id' => $paciente->id])
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
            'fnacimiento'  => ['nullable', 'date_format:Y-m-d'],
            'lnacimiento'  => ['nullable', 'string', 'max:100'],
            'codeestado'   => ['nullable', 'in:' . implode(',', array_keys(FormularioDePacienteVista::ESTADOS_CIVILES))],
            'direccion'    => ['nullable', 'string', 'max:200'],
            'telefono'     => ['nullable', 'string', 'max:20'],
            'email'        => ['nullable', 'email', 'max:100'],
            'profesion'    => ['nullable', 'string', 'max:50'],
            'ocupacion'    => ['nullable', 'string', 'max:100'],
            'escolaridad'  => ['nullable', 'string', 'max:100'],
            'codesegemp'   => ['nullable', 'string', 'max:3'],
            'dependencia'  => ['nullable', 'string', 'max:30'],
            'fingreso'     => ['nullable', 'date_format:Y-m-d'],
            'atender_hoy'  => ['nullable', 'boolean'],
        ], [
            'cedula.required'    => 'Escribe la cédula o marca "Sin cédula (S.C.)".',
            'cedula.regex'       => 'La cédula solo lleva números (hasta 10 dígitos).',
            'apellidos.required' => 'El apellido es obligatorio.',
            'nombres.required'   => 'El nombre es obligatorio.',
            'email.email'        => 'Revisa el correo: no tiene forma de correo.',
            'fnacimiento.date_format' => 'La fecha de nacimiento va como día/mes/año.',
        ]);
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
