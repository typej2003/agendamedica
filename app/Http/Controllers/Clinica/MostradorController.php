<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\AsignarMedicoACita;
use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CitaNoAtendible;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\ReordenarCola;
use App\Actions\Consulta\AbrirConsulta;
use App\Actions\Pacientes\AsignarHistoria;
use App\Clinica\Consulta\PacienteDeLaCita;
use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Las acciones del mostrador sobre una cita —confirmar, **atender (que ahora abre la consulta, WEB-4.1)**,
 * cobrar, reordenar la cola y pasar un paciente sin historia a cita formal—, todas sobre las **Actions
 * compartidas** con el sync del móvil (PLAN-WEB.md, R3). El controlador solo valida que la cita sea del
 * contexto y traduce el resultado a un mensaje.
 *
 * "Atender" es el único de estos métodos que **decide un flujo** (los cuatro casos de la consulta) y no
 * solo traduce una escritura: por eso está documentado caso por caso y delega todo lo demás —el número de
 * la consulta, la marca de atendida— en sus Actions. También es el único que **no es de la secretaría**
 * (WEB-4.9): confirmar, cobrar, agendar y reordenar siguen siendo suyos.
 */
class MostradorController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private PacienteDeLaCita $pacienteDeLaCita)
    {
    }

    /** Confirmar la cita (o quitarle la confirmación, mientras no esté atendida). */
    public function confirmar(Request $request, Cola $cola, ConfirmarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        $estado = (int) $request->input('estado', Cola::ESTADO_CONFIRMADA);

        if ($estado === Cola::ESTADO_NO_CONFIRMADA && (int) $cita->atendido === 1) {
            return back()->with('error', 'Una cita ya atendida no se puede quedar sin confirmar.');
        }

        $accion->ejecutar($cita, $estado, null, 'web');

        return back()->with(
            'estado',
            $estado === Cola::ESTADO_NO_CONFIRMADA ? 'Se quitó la confirmación.' : 'Cita confirmada.'
        );
    }

    /**
     * **Asignarle el médico a una cita** (Fase 3 del cambio de `reg_medico`, 2026-10-20): lo que la
     * secretaría hace desde la fila de la agenda antes de la consulta, y lo que el médico puede hacer con la
     * suya (asignarse o asignar a otro del consultorio).
     *
     * La cita de un **centro** nace a nombre del centro y sin doctor (`cola.medico_id = null`); acá se elige
     * quién la atiende. Las dos reglas —el doctor tiene que atender en ese consultorio y una cita **ya
     * atendida** no cambia de médico— viven en `AsignarMedicoACita`, que es el único lugar donde se escribe
     * `medico_id`/`medico`. Acá sólo se valida que la cita sea del contexto y se traduce el resultado.
     *
     * El rol no se pregunta: la ruta está en el grupo de la agenda (`Root|Medico|Secretaria`), que es
     * exactamente quién puede asignar. **No hay 403 nuevo**: atender sigue siendo sólo del médico
     * (`atender()`), asignar no lo es.
     */
    public function asignarMedico(Request $request, Cola $cola, AsignarMedicoACita $asignar)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        $datos = $request->validate([
            'medico_id' => ['required', 'integer'],
        ], [
            'medico_id.required' => 'Elige el médico que atiende la cita.',
            'medico_id.integer'  => 'El médico elegido no es válido.',
        ]);

        try {
            $resultado = $asignar->ejecutar($cita, (int) $datos['medico_id'], FechaDelConsultorio::ahora(), 'web');
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        $nombre = $resultado['medico']->nombreMostrar;

        return back()->with(
            'estado',
            $resultado['aplicadas'] === []
                ? 'La cita ya era de ' . $nombre . '.'
                : 'La cita quedó a nombre de ' . $nombre . '.'
        );
    }

    /**
     * **"Atender" es sólo del médico** (decisión del usuario, 2026-10-11; WEB-4.9). La secretaría agenda,
     * mueve la cita, cambia el orden de la cola, confirma y cobra; **no atiende**: el `atendido = 1` de una
     * cita sólo lo produce el médico —al abrir la consulta desde el mostrador o desde la agenda—, que es lo
     * que el escritorio hace en el `open` de `w_consulta_principal` (`:4416-4421`) y la misma regla que ya
     * decide quién entra al shell (`User::trabajaLaConsulta()`, PLAN-WEB.md §3.14).
     *
     * Quien no trabaja la consulta recibe **403** y **no se toca nada**: ni `cola.atendido`, ni la
     * confirmación que "atender" implica, ni `sync_changes`, ni se abre ninguna consulta. El 403 es el
     * mismo "no puedes hacer esto" que la ruta del shell le da a la secretaría (y no un redirect con aviso
     * como el de la cita movida o la cita sin historia): acá no falla el estado de la cita —es atendible—
     * sino quién la atiende, que es exactamente lo que el grupo `role:Root|Medico` de `/consulta` responde
     * con 403. La comprobación va **antes** de resolver la cita, como ese middleware: la respuesta no
     * depende de si la cita existe.
     *
     * **"Atender" abre la consulta** (WEB-4.1, PLAN-WEB.md §3.10 y wiki `04-historia-clinica.md`): lo
     * que en el legado hace el `open` de `w_consulta_principal` —abre la consulta del día, la crea si no
     * existe y deja la cita atendida (`UPDATE cola SET atendido = 1`)—, en un solo POST.
     *
     * Los cuatro casos, en este orden:
     *
     *  1. **La cita no dice a quién atender** (sin `numhistoria` ni ancla): no hay nada que abrir.
     *  2. **El paciente no tiene historia**: no se atiende sin historia (confirmado con Alexander,
     *     2026-09-23). Se lo manda a asignarla (WEB-3.3, la Action `AsignarHistoria`) y **no se crea
     *     ninguna consulta**. El móvil la llena en el momento porque trabaja sin conexión; la web tiene
     *     la ficha, que es donde la regla del número ya vive, así que no la duplica.
     *  3. **La cita ya está atendida**: el botón era "Editar consulta" y entra a la consulta del día
     *     **sin marcar nada** (no se deshace ni se vuelve a marcar). Si por lo que sea no hay consulta de
     *     ese día, se abre una —la pantalla tiene que existir para poder editar—.
     *  4. **La cita no está atendida**: se abre —o crea— la consulta del día, se marca la cita como
     *     atendida (`AtenderCita`, que **implica confirmada**) y recién ahí se entra. El orden importa:
     *     no se marca una cita cuya consulta no se pudo abrir, que es lo que hace el móvil.
     *
     * La pregunta del legado "¿re-atender o consulta nueva?" la resuelve la vista de día con su diálogo:
     * "re-atender" (el POST normal) reusa la consulta de hoy si el paciente ya tiene una, y "consulta
     * nueva" manda `nueva=1`, que numera la siguiente aunque haya una hoy.
     */
    public function atender(Request $request, Cola $cola, AbrirConsulta $abrir, AtenderCita $atenderCita)
    {
        // La consulta es del médico y "atender" es abrirla (WEB-4.9): la secretaría no pasa de acá y la
        // cita queda intacta. Es la misma regla que protege la ruta del shell, con la misma respuesta.
        abort_unless((bool) $request->user()?->trabajaLaConsulta(), 403);

        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        $registros = $this->registrosDelMedico($contexto['medico']);
        $pacienteId = $this->pacienteDeLaCita->opcional($cita, $registros);

        if ($pacienteId === null) {
            return back()->with('error', $this->avisoDeCitaSinPaciente());
        }

        // El día de la consulta es el de la cita, en la zona del consultorio (`FechaDelConsultorio`):
        // "Atender" solo se ofrece el día de la cita, así que es hoy, pero la fuente es la cita.
        $dia = FechaDelConsultorio::dia($cita->fecha ? Carbon::parse($cita->fecha)->toDateString() : null);

        // La cita ya atendida no se vuelve a marcar: solo se entra a editar la consulta del día (o se
        // abre una si no hay, para que la pantalla tenga qué mostrar). No pide confirmación porque no
        // hay nada que marcar; lo resuelve el diálogo de la vista.
        if (! $this->citaPorAtender($cita)) {
            return $this->entrarALaConsulta($request, $cita, $abrir, $registros, $pacienteId, $dia, $contexto['medico']);
        }

        try {
            // La consulta que se cree queda **del doctor del contexto** (2026-10-20):
            // `medico_id` y su clave legacy, que es lo que después resuelve quién firma la hoja.
            $abierta = $abrir->abrir($cita, $registros, $dia, $request->boolean('nueva'), $contexto['medico']);
        } catch (InvalidArgumentException $excepcion) {
            return $this->sinHistoria($pacienteId, $excepcion->getMessage());
        }

        // La marca después de abrir: si la consulta no se pudo abrir, la cita no queda atendida.
        //
        // El "cuándo" se resuelve **en la zona del consultorio** y una sola vez para la escritura: es la
        // marca con la que `RegistroDeCambios` decide si la edición gana (last-write-wins por columna)
        // frente a lo que ya subió el teléfono. No se pasa `null` para que la Action no tenga que
        // adivinar la zona (PLAN-WEB.md: "hoy" y "ahora" del negocio son los del consultorio).
        //
        // `AtenderCita` exige la regla "cita atendida ⇒ tiene historia" (WEB-4.8): si la cita no la tiene y
        // no se la puede resolver desde el vínculo del paciente, rechaza la marca. Acá no debería pasar —el
        // caso 2 ya mandó a asignarla—, pero una cita sin paciente identificable (las filas del legado con
        // `numhistoria` nulo y sin ancla) sí llega hasta acá, y tiene que volver con un aviso en vez de con
        // un error del servidor.
        //
        // **La cita sin doctor la toma este médico** (Fase 3 del cambio de `reg_medico`): la cita de un
        // centro nace a nombre del centro, y al atenderla queda a nombre del doctor del contexto.
        try {
            $atenderCita->ejecutar($cita, true, FechaDelConsultorio::ahora(), 'web', $contexto['medico']);
        } catch (CitaNoAtendible $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        return $this->irALaConsulta(
            $abierta->consulta->id,
            'La cita quedó atendida y la consulta N.º ' . $abierta->nroconsulta() . ' está abierta.'
        );
    }

    /** @param  array<int,string>  $registros */
    private function entrarALaConsulta(
        Request $request,
        Cola $cita,
        AbrirConsulta $abrir,
        array $registros,
        int $pacienteId,
        Carbon $dia,
        Medico $medico,
    ): RedirectResponse {
        try {
            // `nueva` también vale acá: desde una cita ya atendida se puede abrir la consulta nueva del
            // día (el "consulta nueva" del legado no exige que la cita esté sin atender).
            $abierta = $abrir->abrir($cita, $registros, $dia, $request->boolean('nueva'), $medico);
        } catch (InvalidArgumentException $excepcion) {
            return $this->sinHistoria($pacienteId, $excepcion->getMessage());
        }

        return $this->irALaConsulta($abierta->consulta->id, 'Consulta N.º ' . $abierta->nroconsulta() . ' abierta.');
    }

    /**
     * ¿Esta cita es de las que hay que marcar al atender? No si ya está atendida, y tampoco si el
     * escritorio la movió (esa fila es la vieja: el paciente ya está en la fecha nueva).
     */
    private function citaPorAtender(Cola $cita): bool
    {
        return ! $cita->movida_escritorio && (int) $cita->atendido !== 1;
    }

    /** A la consulta, con el mensaje de qué pasó. */
    private function irALaConsulta(int $consultaId, string $mensaje): RedirectResponse
    {
        // Al darle «Atender» las alertas del paciente se avisan de nuevo (WEB-11.6).
        session()->put('alertas_vistas', array_values(array_diff((array) session('alertas_vistas', []), [$consultaId])));

        return redirect()->route('clinica.consulta', $consultaId)->with('estado', $mensaje);
    }

    /**
     * Sin historia no hay consulta: al paciente hay que numerársela primero. Va a la **ficha**, que es
     * donde vive la acción (WEB-3.3) y donde el médico o la secretaría la resuelven sin perder de vista
     * la agenda.
     */
    private function sinHistoria(int $pacienteId, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('clinica.pacientes.ver', $pacienteId)
            ->with('error', $mensaje . ' Se la asignas desde acá y después vuelves a atender la cita.');
    }

    private function avisoDeCitaSinPaciente(): string
    {
        return 'Esta cita no tiene un paciente identificable al que atender.';
    }

    /** Cobrar: abono parcial, sumado a lo ya pagado (ver `CobrarCita`). */
    public function cobrar(Request $request, Cola $cola, CobrarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'recibido' => ['required', 'numeric', 'min:0'],
            'monto'    => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $accion->recibir(
                $cita,
                (float) $datos['recibido'],
                isset($datos['monto']) ? (float) $datos['monto'] : null,
                null,
                'web'
            );
        } catch (InvalidArgumentException $excepcion) {
            // Cobrar de más: vuelve con el aviso en el campo `recibido` y sin escribir nada (la Action
            // revisa antes de tocar la cita, así que tampoco queda el precio fijado a medias).
            throw ValidationException::withMessages(['recibido' => $excepcion->getMessage()]);
        }

        return back()->with('estado', 'Cobro registrado.');
    }

    /**
     * **"Pasar a cita formal"** (WEB-2.11): asignarle la historia al paciente de una cita que todavía no
     * tiene número, con lo que la cita deja de ser "sin historia" —es la misma fila, enlazada por
     * `cola.numhistoria`— y el paciente queda listo para atender.
     *
     * Es la acción del `pb_3` de `w_hacer_cita` (`:801-805`) → `w_pasar_pacientes_n_reg_consulta_cita`,
     * pero sobre el modelo de AppDDR: el escritorio crea un paciente nuevo en `pacientes` y otra cita;
     * acá el paciente y la cita **ya existen** (los creó el alta de la web, WEB-2.2, anclados por
     * `paciente_sinhistoria_id`) y lo único que falta es el número. La regla vive en la Action
     * compartida con el sync del móvil: `AsignarHistoria`.
     *
     * El número **no se puede adelantar** en el formulario —lo calcula el servidor al asignarlo con el
     * correlativo vigente del médico—, así que la pantalla confirma el efecto antes y acá se informa el
     * número después. Idempotente: si el paciente ya la tenía, la Action devuelve la suya y no numera
     * dos veces (el doble clic o el reintento terminan en el mismo lugar).
     *
     * Vuelve **explícitamente** a la agenda del día y la sede de la cita (no con `back()`): el destino
     * es el mismo con o sin el `Referer` que manda el navegador, que es la única forma de que el
     * resultado no dependa de cómo llegó la petición.
     */
    public function pasarACitaFormal(Request $request, Cola $cola, AsignarHistoria $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);
        $volver = $this->urlDeLaAgendaDe($request, $cita);

        $pacienteId = $this->pacienteDeLaCita($cita);

        if ($pacienteId === null) {
            return $volver->with('error', 'Esta cita no tiene un paciente identificable al que asignarle la historia.');
        }

        $teniaHistoria = $cita->numhistoria !== null;

        try {
            // El número es del **consultorio de la cita** (el tenant del vínculo): el correlativo y la
            // cita "sin historia" que se enlaza son los de ese registro, no los de los demás del médico.
            $historia = $accion->ejecutar($contexto['medico'], $cita->reg_medico, $pacienteId);
        } catch (InvalidArgumentException $excepcion) {
            return $volver->with('error', $excepcion->getMessage());
        }

        return $volver->with('estado', $teniaHistoria
            ? 'Este paciente ya tenía historia: H. ' . $historia->numhistoria . '.'
            : 'Cita formal: al paciente se le asignó la historia H. ' . $historia->numhistoria . ' y su cita quedó enlazada.');
    }

    /**
     * La agenda del día y la sede de la cita: donde el usuario ve el resultado de la acción. La sede
     * sale de la cita (no del `Referer`), así se cae al panel de la cita que se acaba de formalizar.
     */
    private function urlDeLaAgendaDe(Request $request, Cola $cita): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return redirect()->route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => Carbon::parse($cita->fecha)->toDateString(),
            'sede'  => $cita->medical_center_id !== null
                ? ($contexto['sedes']->firstWhere('medical_center_id', (int) $cita->medical_center_id)?->id ?? 'todas')
                : 'todas',
        ]);
    }

    /**
     * `pacientes.id` del paciente de la cita: el vínculo normal es la historia
     * (`medico_pacientes.numhistoria`) y, mientras no la tenga, el ancla
     * `cola.paciente_sinhistoria_id`. Una cita sin ninguna de las dos no dice a quién numerar (en los
     * datos del legado las hay: filas de `cola` con `numhistoria` nulo).
     */
    private function pacienteDeLaCita(Cola $cita): ?int
    {
        if ($cita->numhistoria !== null) {
            return MedicoPaciente::where('reg_medico', $cita->reg_medico)
                ->where('numhistoria', (int) $cita->numhistoria)
                ->value('paciente_id');
        }

        return $cita->paciente_sinhistoria_id !== null ? (int) $cita->paciente_sinhistoria_id : null;
    }

    /**
     * Reordenar la cola: **una** operación de sync, no N updates (el arrastre manda el movimiento).
     * Responde JSON al arrastre y redirige si el formulario no trae JS.
     */
    public function reordenar(Request $request, ReordenarCola $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'record_id' => ['required', 'integer'],
            'from'      => ['required', 'integer'],
            'to'        => ['required', 'integer'],
            'fecha'     => ['required', 'date'],
        ]);

        $cita = Cola::where('id', $datos['record_id'])->firstOrFail();
        $this->citaDelContexto($request, $cita);

        $accion->ejecutar(
            $cita,
            (int) $datos['from'],
            (int) $datos['to'],
            (string) $datos['fecha'],
            [$contexto['reg_medico']],
            null,
            'web'
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('estado', 'Orden actualizado.');
    }
}
