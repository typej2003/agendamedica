<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\CrearCita;
use App\Actions\Agenda\EditarCita;
use App\Actions\Agenda\EliminarCita;
use App\Actions\Agenda\ReordenarCola;
use App\Actions\Notificaciones\EnviarRecordatorio;
use App\Actions\Pacientes\CrearPaciente;
use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\DiasNoLaborables;
use App\Clinica\Agenda\Jornada;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MotivoCita;
use App\Models\NotificacionCita;
use App\Models\Office;
use App\Models\Paciente;
use App\Notificaciones\Canal;
use App\Notificaciones\ExcepcionDeEnvio;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Agenda y secretaría de la web clínica (PLAN-WEB.md, F2): la vista **día / semana / mes por sede**,
 * con la modalidad y el cupo de cada jornada, y las acciones del mostrador —confirmar, atender,
 * cobrar y reordenar— sobre las **Actions compartidas** con el sync del móvil.
 *
 * Nada de la lectura se recalcula en la vista: la jornada, la posición, la modalidad y el cupo los
 * resuelve `ArmadorDeAgenda` (portado del móvil); acá solo se decide qué rango y qué sede se mira.
 */
class AgendaController extends Controller
{
    private const VISTAS = ['dia', 'semana', 'mes'];

    /**
     * Hora con la que nace una cita cuando la sede no tiene bloques configurados: `cola.hora_ini` es
     * obligatoria en el esquema legado (el listado la muestra y ordena por ella).
     */
    private const HORA_POR_DEFECTO = '08:00';

    public function __construct(
        private ArmadorDeAgenda $armador,
        private MensajeDeRecordatorio $mensajes,
        private DiasNoLaborables $diasNoLaborables,
    ) {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];

        $vista = in_array($request->query('vista'), self::VISTAS, true) ? $request->query('vista') : 'dia';
        $fecha = $this->fecha($request->query('fecha'));
        [$desde, $hasta] = $this->rango($vista, $fecha);

        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $sedeId = $this->sedePedida($request, $contexto);
        $centroId = $sedeId ? $sedes->firstWhere('id', $sedeId)?->medical_center_id : null;

        // La posición del paciente se calcula sobre **todas** las citas del rango, no sobre las que
        // quedan visibles al filtrar: si la secretaria filtra, el paciente 7 sigue siendo el 7.
        $delRango = $this->armador->citas($regMedico, $desde, $hasta);
        $posiciones = $this->armador->posiciones($this->armador->jornadas($delRango, $sedes));

        $visibles = $centroId === null
            ? $delRango
            : $delRango->where('centroId', $centroId)->values();

        $conteos = $visibles->groupBy('fecha')->map->count();

        // Días no laborables (WEB-2.8): el aviso del día que se está mirando y, en la vista de mes,
        // la marca de cada día del rango para no ver sólo el conteo de citas.
        $noLaborables = $this->diasNoLaborables->entre($regMedico, $desde->copy(), $hasta->copy());
        $hoyNoLaborable = $this->diasNoLaborables->delDia($regMedico, $this->claveDelMedico($contexto), $fecha);

        // Recordatorio por cita (WEB-2.6): el texto del **médico de esa cita** ya armado, para que el
        // diálogo de envío de cada fila lo ofrezca sin una consulta por fila.
        [$plantillas, $medicos, $medicoPorDefecto] = $this->medicosYPlantillas($regMedico);

        return view('clinica.agenda.index', [
            'vista'         => $vista,
            'fecha'         => $fecha,
            'sedeId'        => $sedeId,
            'sedes'         => $sedes,
            'jornadas'      => $this->armador->jornadas($visibles, $sedes),
            'posiciones'    => $posiciones,
            'conteosPorDia' => $conteos,
            'noLaborables'  => $noLaborables,
            'noLaborable'   => $hoyNoLaborable !== null ? $this->diasNoLaborables->aviso($hoyNoLaborable) : null,
            'semana'        => $this->diasDeLaSemana($fecha),
            'semanasDelMes' => $vista === 'mes' ? $this->semanasDelMes($fecha, $conteos) : [],
            'totalRango'    => $visibles->count(),
            'ahora'         => Carbon::now(),
            'mensajes'      => $this->mensajesDeRecordatorio(
                $visibles,
                $plantillas,
                $medicos,
                $medicoPorDefecto,
                $this->centrosDe($sedes),
            ),
            'canales'       => Canal::etiquetas(),
        ]);
    }

    // ------------------------------------------------------------------------------- Alta y edición

    /**
     * El formulario de "Nueva cita".
     *
     * La sede y el día definen la **jornada** (modalidad, cupo y qué número le toca al próximo), así
     * que se eligen con un GET que vuelve a esta pantalla y el formulario se arma con eso ya
     * resuelto en el servidor. Es la misma decisión que la ventana `w_nueva_cita` del escritorio,
     * que se abre sabiendo el día y el médico.
     */
    public function nueva(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // Se puede llegar con el paciente ya elegido (desde su ficha); si no, se busca en el form.
        $paciente = $request->query('paciente_id') !== null
            ? $this->pacienteDelContexto($contexto, (int) $request->query('paciente_id'))
            : null;

        return view('clinica.agenda.formulario', $this->formulario($request, $contexto, [
            'cita'            => null,
            'accion'          => route('clinica.agenda.crear'),
            'fecha'           => $this->fecha($request->query('fecha')),
            'sedeId'          => $this->sedePedida($request, $contexto),
            'valores'         => ['paciente_id' => $paciente?->id],
            'pacienteElegido' => $paciente ? $this->fichaDe($paciente, $this->numHistoriaDe($contexto, $paciente)) : null,
            'pendiente'       => null,
        ]));
    }

    /**
     * Guardar la cita nueva.
     *
     * Si el paciente **ya tiene una cita pendiente** en ese consultorio no se escribe nada todavía:
     * se vuelve a mostrar el formulario con la pregunta —mover esa cita a la fecha nueva, agendar
     * otra de todos modos o cancelar—, que es lo que hace el móvil. La regla es la de Alexander: un
     * paciente tiene **una** cita pendiente por médico y consultorio; la excepción de los
     * tratamientos (masajistas, terapias, donde sí se agendan varias) hoy se resuelve preguntando
     * (queda por automatizar: WEB-2.17).
     */
    public function crear(Request $request, CrearCita $crear, CrearPaciente $crearPaciente, EditarCita $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];
        $valores = $request->validate($this->reglas());

        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $sede = $this->sedeElegida($sedes, $valores['sede'] ?? null);
        $fecha = Carbon::createFromFormat('!Y-m-d', $valores['fecha']);
        $bloques = $sede ? $sede->bloquesDe($fecha->dayOfWeekIso) : collect();
        $this->exigirHoraSiEsConHora($sede, $valores['hora'] ?? null);
        $hora = $this->horaEfectiva($valores['hora'] ?? null, $valores['bloque'] ?? null, $bloques);
        $centroId = $sede?->medical_center_id;

        // A quién se le agenda, **sin escribir nada todavía**: el paciente elegido, o la ficha que ya
        // exista con la cédula nueva (el alta enlaza, no duplica).
        $objetivo = $this->identificarPaciente($contexto, $valores);
        $pendiente = $this->armador->citaPendiente(
            $regMedico,
            $objetivo['numhistoria'],
            $objetivo['paciente']?->id,
            $centroId,
        );

        if ($pendiente !== null && ($valores['decision'] ?? null) === null) {
            return view('clinica.agenda.formulario', $this->formulario($request, $contexto, [
                'cita'            => null,
                'accion'          => route('clinica.agenda.crear'),
                'fecha'           => $fecha,
                'sedeId'          => $sede?->id,
                'valores'         => $valores,
                'pacienteElegido' => $this->fichaDe($objetivo['paciente'], $objetivo['numhistoria'], $valores),
                'pendiente'       => $pendiente,
            ]));
        }

        if (($valores['decision'] ?? null) === 'cancelar') {
            return $this->volverALaAgenda($fecha, $sede, 'No se agendó nada.', $this->avisoDeRedireccion($contexto, $fecha));
        }

        // La ficha y su **vínculo con el médico** los garantiza la Action: si la cédula ya existía,
        // la enlaza (completando solo lo que estaba vacío) en vez de duplicarla. Cuando el paciente
        // se eligió del listado ya está enlazado y no hay nada que crear; y la ficha nueva se crea
        // recién acá, para que cancelar la pregunta no deje un paciente suelto.
        $paciente = ! empty($valores['paciente_id'])
            ? $objetivo['paciente']
            : $crearPaciente->ejecutar(
                $this->columnasDelPacienteNuevo($valores),
                $contexto['medico'],
                $regMedico,
            );

        $jornada = $this->armador->jornadaPara($regMedico, $sedes, $fecha, $centroId, $hora);
        $monto = $this->montoDe($valores, $contexto, $sede);

        // "Mover esa cita a la nueva fecha": es **la misma fila** movida, no una cita nueva (conserva
        // lo cobrado), así que la cita pendiente se edita y no se crea nada.
        if (($valores['decision'] ?? null) === 'mover' && $pendiente !== null) {
            $editar->ejecutar(
                $pendiente['cita'],
                $this->cambiosDeMovimiento($pendiente, $sedes, $fecha, $hora, $centroId, $jornada, $valores['motivo'] ?? null, $monto),
                Carbon::now(),
                'web',
            );

            return $this->volverALaAgenda($fecha, $sede, 'Se movió la cita que ya tenía.', $this->avisoDeRedireccion($contexto, $fecha));
        }

        $crear->ejecutar($regMedico, $this->filaDeCita($objetivo, $paciente, $fecha, $hora, $jornada, $centroId, $valores, $contexto, $monto), Carbon::now(), 'web');

        return $this->volverALaAgenda($fecha, $sede, 'Cita agendada.', $this->avisoDeRedireccion($contexto, $fecha));
    }

    /** El formulario de edición, con la cita puesta (misma vista que "Nueva cita"). */
    public function editar(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return $this->volverALaAgenda(Carbon::parse($cita->fecha), null, null)
                ->with('error', 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.');
        }

        if ((int) $cita->atendido === 1) {
            return $this->volverALaAgenda(Carbon::parse($cita->fecha), null, null)
                ->with('error', 'Una cita ya atendida no se reagenda. Si hay que corregir el monto, usá Cobrar.');
        }

        $sedes = $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
        $sede = $sedes->first(fn (Office $opcion) => $opcion->medical_center_id === ($cita->medical_center_id !== null ? (int) $cita->medical_center_id : null));
        $paciente = $this->fichaDeLaCita($cita);

        return view('clinica.agenda.formulario', $this->formulario($request, $contexto, [
            'cita'   => $cita,
            'accion' => route('clinica.agenda.actualizar', $cita->id),
            'fecha'  => Carbon::parse($cita->fecha),
            'sedeId' => $sede?->id,
            'valores' => [
                'sede'        => $sede?->id,
                'fecha'       => Carbon::parse($cita->fecha)->toDateString(),
                'hora'        => $cita->hora_ini !== null ? substr($cita->hora_ini, 0, 5) : null,
                // La razón vive en `tipo` (escritorio) o en `motivo` (móvil): se preselecciona el que
                // esté. Si no está en el catálogo, la vista agrega la opción para no perderlo.
                'motivo'      => $cita->tipo ?: $cita->motivo,
                'monto'       => $cita->monto,
                'paciente_id' => $paciente?->id,
            ],
            'pacienteElegido' => $paciente !== null
                ? $this->fichaDe($paciente, $cita->numhistoria !== null ? (int) $cita->numhistoria : null)
                : null,
            'pendiente' => null,
        ]));
    }

    /**
     * Guardar la edición (reagendar es editar la misma fila, wiki `02-modulo-agenda.md` §4).
     *
     * El paciente no se toca: cambiar de paciente es otra acción, sin reglas definidas todavía.
     */
    public function actualizar(Request $request, Cola $cola, EditarCita $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.');
        }

        if ((int) $cita->atendido === 1) {
            return back()->with('error', 'Una cita ya atendida no se reagenda. Si hay que corregir el monto, usá Cobrar.');
        }

        $valores = $request->validate($this->reglas());
        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $sede = $this->sedeElegida($sedes, $valores['sede'] ?? null);
        $fecha = Carbon::createFromFormat('!Y-m-d', $valores['fecha']);
        $bloques = $sede ? $sede->bloquesDe($fecha->dayOfWeekIso) : collect();
        $this->exigirHoraSiEsConHora($sede, $valores['hora'] ?? null);
        $hora = $this->horaEfectiva($valores['hora'] ?? null, $valores['bloque'] ?? null, $bloques);
        $centroId = $sede?->medical_center_id;

        $jornada = $this->armador->jornadaPara($regMedico, $sedes, $fecha, $centroId, $hora);
        $jornadaActual = $this->armador->jornadaPara(
            $regMedico,
            $sedes,
            Carbon::parse($cita->fecha),
            $cita->medical_center_id !== null ? (int) $cita->medical_center_id : null,
            $cita->hora_ini,
        );

        $cambios = [
            'fecha'             => $fecha->toDateString(),
            'hora_ini'          => $hora,
            'turno'             => Jornada::turnoDe($hora),
            'medical_center_id' => $centroId,
            'tipo'              => $valores['motivo'] ?? null,
            'monto'             => $this->montoDe($valores, $contexto, $sede),
        ];

        // Si cambió de jornada va **al final de la nueva**, y lo que valía para la fecha vieja se
        // reinicia: la confirmación y la constancia del recordatorio (el paciente ya recibió el
        // mensaje de otro día). Es lo que hace el móvil al reagendar.
        if ($jornadaActual->clave() !== $jornada->clave()) {
            $cambios['numorden'] = $jornada->siguienteNumero();
            $cambios['estado'] = Cola::ESTADO_NO_CONFIRMADA;
            $cambios['sms_text'] = null;
        }

        $aplicadas = $editar->ejecutar($cita, $cambios, Carbon::now(), 'web');

        return $this->volverALaAgenda(
            $fecha,
            $sede,
            $aplicadas === [] ? 'No había nada que cambiar.' : 'Cita actualizada.',
            $this->avisoDeRedireccion($contexto, $fecha),
        );
    }

    /** Eliminar: lo decide `EliminarCita` (una cita atendida o con pago no se borra). */
    public function eliminar(Request $request, Cola $cola, EliminarCita $eliminar)
    {
        $cita = $this->citaDelContexto($request, $cola);

        try {
            $eliminar->ejecutar($cita);
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        return back()->with('estado', 'Cita eliminada.');
    }

    /** Confirmar la cita (o quitarle la confirmación, mientras no esté atendida). */
    public function confirmar(Request $request, Cola $cola, ConfirmarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.');
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
     * Marcar la cita como atendida. **Atender implica confirmado** (lo resuelve la Action).
     *
     * Abrir la consulta es otra cosa: llega con el módulo Consulta (F4), que es donde existe la
     * pantalla. Hasta entonces "Atender" deja la marca, que es el dato que la consulta va a usar.
     */
    public function atender(Request $request, Cola $cola, AtenderCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.');
        }

        $accion->ejecutar($cita, true, null, 'web');

        return back()->with('estado', 'Cita marcada como atendida.');
    }

    /** Cobrar: abono parcial, sumado a lo ya pagado (ver `CobrarCita`). */
    public function cobrar(Request $request, Cola $cola, CobrarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'recibido' => ['required', 'numeric', 'min:0'],
            'monto'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $accion->recibir(
            $cita,
            (float) $datos['recibido'],
            isset($datos['monto']) ? (float) $datos['monto'] : null,
            null,
            'web'
        );

        return back()->with('estado', 'Cobro registrado.');
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

    // ------------------------------------------------- Recordatorios y envíos del día (WEB-2.6/2.7)

    /**
     * Mandar el recordatorio de **una** cita, por el canal elegido (WEB-2.6).
     *
     * Es el camino del móvil (Paso 21.B) traído a la web: el diálogo ofrece los tres canales, el
     * texto del médico **de esa cita** y avisa si al paciente le falta el dato. El envío en sí lo hace
     * `EnviarRecordatorio`, la misma Action que usa el endpoint del app (PLAN-WEB.md, R3).
     *
     * Las citas confirmadas o atendidas no reciben recordatorio (regla de la wiki): el diálogo no se
     * ofrece y esto lo vuelve a comprobar, porque un POST se puede mandar a mano.
     */
    public function notificar(Request $request, Cola $cola, EnviarRecordatorio $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.');
        }

        if ((int) $cita->atendido === 1) {
            return back()->with('error', 'Esta cita ya está atendida: no se le manda el recordatorio.');
        }

        if (in_array((int) $cita->estado, [Cola::ESTADO_CONFIRMADA, Cola::ESTADO_CONFIRMADA_PACIENTE], true)) {
            return back()->with('error', 'Esta cita ya está confirmada: no hace falta recordársela.');
        }

        $datos = $request->validate([
            'canal'   => ['required', 'in:' . implode(',', Canal::todos())],
            'mensaje' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $notificacion = $accion->ejecutar($cita, $datos['canal'], $datos['mensaje'] ?? null, $request->user());
        } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        if ($notificacion->estado !== NotificacionCita::ESTADO_ENVIADA) {
            return back()->with('error', 'El proveedor no aceptó el mensaje. El intento quedó registrado.');
        }

        return back()->with('estado', 'Recordatorio enviado por ' . Canal::etiqueta($datos['canal']) . '.');
    }

    /**
     * La ventana de **envío del día** (WEB-2.7): los pacientes de la jornada, el canal y el texto, como
     * `w_sms_enviar` / `w_correo_enviar` del escritorio. Ahí el usuario tilde a quién le manda.
     */
    public function envio(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fecha = $this->fecha($request->query('fecha'));
        $sedes = $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
        $sedeId = $this->sedePedida($request, $contexto);
        // Misma regla que el recordatorio de una cita: no se le recuerda a quien ya confirmó o está
        // siendo atendido. El listado que se **imprime** sí las lleva (ahí el estatus es el dato).
        $citas = $this->citasDeLaJornada($contexto['reg_medico'], $sedes, $fecha, $sedeId)
            ->filter(fn (CitaDeAgenda $cita) => $cita->puedeRecordar())
            ->values();

        [$plantillas, $nombres, $medicoPorDefecto] = $this->medicosYPlantillas($contexto['reg_medico']);
        $canalPedido = $request->query('canal');

        return view('clinica.agenda.envio', [
            'fecha'    => $fecha,
            'sedeId'   => $sedeId,
            'sede'     => $sedeId ? $sedes->firstWhere('id', $sedeId) : null,
            'citas'    => $citas,
            'mensajes' => $this->mensajesDeRecordatorio($citas, $plantillas, $nombres, $medicoPorDefecto, $this->centrosDe($sedes)),
            'canales'  => Canal::etiquetas(),
            // El SMS es el del escritorio (su ventana, y su columna `cola.sms`): es el que viene elegido.
            'canal'    => Canal::existe($canalPedido) ? $canalPedido : Canal::SMS,
        ]);
    }

    /**
     * Mandar el recordatorio a **los seleccionados** de la jornada (WEB-2.7).
     *
     * Va cita por cita a propósito: que a un paciente le falte el teléfono, o que el proveedor rechace
     * un mensaje, **no** puede abortar el resto del envío. Cada intento queda registrado igual
     * (`notificaciones_cita`), y lo que falló se le informa al usuario con el nombre del paciente.
     */
    public function enviarMasivo(Request $request, EnviarRecordatorio $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'fecha'   => ['required', 'date'],
            'sede'    => ['nullable'],
            'canal'   => ['required', 'in:' . implode(',', Canal::todos())],
            'mensaje' => ['nullable', 'string', 'max:500'],
            'citas'   => ['required', 'array'],
            'citas.*' => ['integer'],
        ]);

        $enviados = 0;
        $fallidos = [];

        foreach ($datos['citas'] as $id) {
            $cita = Cola::find($id);

            // Tenancy: el POST puede traer cualquier id; solo se toca lo del registro del contexto.
            if (! $cita || $cita->reg_medico !== $contexto['reg_medico']) {
                continue;
            }

            try {
                $notificacion = $accion->ejecutar($cita, $datos['canal'], $datos['mensaje'] ?? null, $request->user());
            } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
                $fallidos[] = $this->nombreDelPacienteDe($cita, $accion) . ': ' . $excepcion->getMessage();
                continue;
            }

            if ($notificacion->estado === NotificacionCita::ESTADO_ENVIADA) {
                $enviados++;
            } else {
                $fallidos[] = $this->nombreDelPacienteDe($cita, $accion) . ': el proveedor no aceptó el mensaje';
            }
        }

        $destino = redirect()->route('clinica.agenda.envio', [
            'fecha' => $datos['fecha'],
            'sede'  => $datos['sede'] ?: 'todas',
            'canal' => $datos['canal'],
        ])->with('estado', sprintf(
            '%d %s por %s.',
            $enviados,
            $enviados === 1 ? 'recordatorio enviado' : 'recordatorios enviados',
            Canal::etiqueta($datos['canal']),
        ));

        if ($fallidos !== []) {
            $destino = $destino->with('error', sprintf(
                '%d sin enviar — %s%s',
                count($fallidos),
                implode('; ', array_slice($fallidos, 0, 3)),
                count($fallidos) > 3 ? '…' : '',
            ));
        }

        return $destino;
    }

    /**
     * El listado del día para imprimir (WEB-2.7): la pantalla que reemplaza al `dw_1.print()` del
     * botón *Imprimir* de `w_hacer_cita` (`d_pacientes_consulta_cita_print`: hora, paciente, cédula,
     * teléfono, razón y estatus, sin las citas que el escritorio movió).
     *
     * Imprime el navegador (PLAN-WEB.md, R6), no el servidor: la vista es lo que se manda a la
     * impresora del consultorio.
     */
    public function imprimir(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fecha = $this->fecha($request->query('fecha'));
        $sedes = $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
        $sedeId = $this->sedePedida($request, $contexto);

        return view('clinica.agenda.imprimir', [
            'fecha'  => $fecha,
            'sede'   => $sedeId ? $sedes->firstWhere('id', $sedeId) : null,
            'medico' => $contexto['medico'],
            'citas'  => $this->citasDeLaJornada($contexto['reg_medico'], $sedes, $fecha, $sedeId),
        ]);
    }

    /**
     * Las citas del día y la sede pedidos, sin las que el escritorio movió.
     *
     * @param  Collection<int,Office>  $sedes
     * @return Collection<int,CitaDeAgenda>
     */
    private function citasDeLaJornada(string $regMedico, Collection $sedes, Carbon $fecha, ?int $sedeId): Collection
    {
        $centroId = $sedeId ? $sedes->firstWhere('id', $sedeId)?->medical_center_id : null;

        return $this->armador->citas($regMedico, $fecha->copy(), $fecha->copy())
            ->when($centroId !== null, fn (Collection $citas) => $citas->where('centroId', $centroId))
            ->reject(fn (CitaDeAgenda $cita) => $cita->movida())
            ->values();
    }

    /**
     * La plantilla y el nombre del **médico de cada cita**, por `evolucion.clave` = `cola.medico`.
     *
     * Es la regla del móvil (Paso 22.B/23): una secretaría manda recordatorios de citas de varios
     * médicos del consultorio y cada uno sale con las palabras de su propio médico. Se resuelve en
     * lote porque la agenda de un día tiene muchas filas y la configuración es una por médico.
     *
     * @return array{0: array<int,string>, 1: array<int,string>, 2: ?string}
     *         plantilla por clave, nombre por clave, y el nombre del médico del registro — para las
     *         citas cuya `clave` no está en `evolucion`, que es lo común en los datos reales.
     */
    private function medicosYPlantillas(string $regMedico): array
    {
        $evoluciones = Evolucion::where('reg_medico', $regMedico)->whereNotNull('clave')->get();
        $medicosPorCorreo = Medico::whereIn('email', $evoluciones->pluck('correo_med')->filter()->unique()->all())
            ->get()
            ->keyBy('email');

        $plantillas = [];
        $nombres = [];

        foreach ($evoluciones as $evolucion) {
            $clave = (int) $evolucion->clave;
            $medico = $evolucion->correo_med ? $medicosPorCorreo->get($evolucion->correo_med) : null;

            $plantillas[$clave] = (string) $evolucion->plantilla_cita;
            $nombres[$clave] = $medico?->nombreMostrar ?? '';
        }

        return [$plantillas, $nombres, Medico::where('reg_medico', $regMedico)->first()?->nombreMostrar];
    }

    /**
     * El mensaje de recordatorio ya armado de cada cita, para que la vista no lo resuelva fila por fila.
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @param  array<int,string>  $plantillas  clave de `evolucion` => plantilla
     * @param  array<int,string>  $nombres  clave => nombre del médico
     * @param  array<int,string>  $centros  `medical_center_id` => nombre de la sede
     * @return array<int,string>  id de cita => mensaje
     */
    private function mensajesDeRecordatorio(
        Collection $citas,
        array $plantillas,
        array $nombres,
        ?string $medicoPorDefecto,
        array $centros
    ): array {
        $mensajes = [];

        foreach ($citas as $cita) {
            $mensajes[$cita->id] = $this->mensajes->armar($plantillas[$cita->medico ?? ''] ?? null, [
                'paciente' => $cita->paciente,
                'fecha'    => FechaClinica::formato($cita->fecha, 'd/m/Y'),
                'hora'     => $cita->horaIni ? substr($cita->horaIni, 0, 5) : '',
                'doctor'   => $nombres[$cita->medico ?? ''] ?? (string) $medicoPorDefecto,
                'centro'   => $centros[$cita->centroId ?? ''] ?? '',
            ]);
        }

        return $mensajes;
    }

    /** @return array<int,string> `medical_center_id` => nombre, para la etiqueta `{centro}`. */
    private function centrosDe(Collection $sedes): array
    {
        return $sedes
            ->mapWithKeys(fn (Office $sede) => [
                (int) $sede->medical_center_id => (string) ($sede->medicalCenter->name ?? ''),
            ])
            ->all();
    }

    /** El nombre del paciente de una cita, para decir **a quién** no se le pudo mandar el recordatorio. */
    private function nombreDelPacienteDe(Cola $cita, EnviarRecordatorio $accion): string
    {
        $paciente = $accion->pacienteDeLaCita($cita);

        return $paciente
            ? trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? ''))
            : 'Cita #' . $cita->id;
    }

    /** Una cita del `reg_medico` del contexto; la de otro médico no se toca ni se confirma que exista. */
    private function citaDelContexto(Request $request, Cola $cola): Cola
    {
        $contexto = $request->attributes->get('contexto_clinico');
        abort_unless($cola->reg_medico === $contexto['reg_medico'], 404);

        return $cola;
    }

    /** La clave con la que se marcan los días no laborables de este médico (`cola_dia_no_labor.medico`). */
    private function claveDelMedico(array $contexto): int
    {
        return $this->diasNoLaborables->claveDe($contexto['medico'], $contexto['reg_medico']);
    }

    /** El aviso del día no laborable ya armado, o `null` si ese día el médico atiende normal. */
    private function avisoDelDia(array $contexto, Carbon $fecha): ?string
    {
        $dia = $this->diasNoLaborables->delDia($contexto['reg_medico'], $this->claveDelMedico($contexto), $fecha);

        return $dia !== null ? $this->diasNoLaborables->aviso($dia) : null;
    }

    /**
     * El aviso del día no laborable como mensaje de la redirección (POST).
     *
     * No bloquea: el escritorio aborta el agendamiento cuando el día tiene motivo y la web **avisa y
     * deja decidir** (decisión del 2026-10-09, WEB-2.8), porque la excepción real —"es feriado pero el
     * Dr. atiende igual"— no puede quedar sin camino. Los mensajes viajan en `avisos`, una lista, para
     * que el aviso del día no se pise con el del resultado de la operación.
     *
     * @return array<int,string>
     */
    private function avisoDeRedireccion(array $contexto, Carbon $fecha): array
    {
        $aviso = $this->avisoDelDia($contexto, $fecha);

        return $aviso !== null ? [$aviso] : [];
    }

    /** La fecha pedida (`Y-m-d`), o hoy. Nunca revienta por un valor mal formado en la URL. */
    private function fecha(?string $valor): Carbon
    {
        try {
            return $valor ? Carbon::createFromFormat('!Y-m-d', $valor) : Carbon::today();
        } catch (\Throwable $e) {
            return Carbon::today();
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(string $vista, Carbon $fecha): array
    {
        return match ($vista) {
            'semana' => [$fecha->copy()->startOfWeek(Carbon::MONDAY), $fecha->copy()->endOfWeek(Carbon::SUNDAY)],
            'mes'    => [$fecha->copy()->startOfMonth(), $fecha->copy()->endOfMonth()],
            default  => [$fecha->copy(), $fecha->copy()],
        };
    }

    /** La sede pedida: por defecto la del contexto; `todas` (o 0) muestra todas. */
    private function sedePedida(Request $request, array $contexto): ?int
    {
        $pedida = $request->query('sede');

        if ($pedida === null) {
            return $contexto['office']->id ?? null;
        }

        if ($pedida === 'todas' || $pedida === '0') {
            return null;
        }

        $id = (int) $pedida;

        return $id > 0 ? $id : null;
    }

    /** @return Collection<int,Carbon> Los 7 días de la semana de [fecha], de lunes a domingo. */
    private function diasDeLaSemana(Carbon $fecha): Collection
    {
        $lunes = $fecha->copy()->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))->map(fn (int $i) => $lunes->copy()->addDays($i));
    }

    /**
     * El mes como semanas de 7 días, cada día con su conteo (0 si no hay citas). Incluye los días
     * de los meses vecinos que completan la primera y la última semana, marcados como `enMes = false`.
     *
     * @param  Collection<string,int>  $conteos
     * @return array<int,array<int,array{fecha: Carbon, enMes: bool, conteo: int}>>
     */
    private function semanasDelMes(Carbon $fecha, Collection $conteos): array
    {
        $cursor = $fecha->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $fin = $fecha->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $mes = $fecha->month;

        $semanas = [];
        $semana = [];

        while ($cursor->lessThanOrEqualTo($fin)) {
            $semana[] = [
                'fecha'  => $cursor->copy(),
                'enMes'  => $cursor->month === $mes,
                'conteo' => (int) ($conteos[$cursor->toDateString()] ?? 0),
            ];

            if (count($semana) === 7) {
                $semanas[] = $semana;
                $semana = [];
            }

            $cursor->addDay();
        }

        return $semanas;
    }

    // ------------------------------------------------------------------------ Ayudas del formulario

    /**
     * Todo lo que necesita el formulario de la cita. Lo usan las tres pantallas que lo muestran:
     * "Nueva cita", "Editar cita" y la repregunta por la cita pendiente.
     *
     * @param  array{cita: ?Cola, accion: string, fecha: Carbon, sedeId: ?int, valores: array, pacienteElegido: ?array, pendiente: ?array}  $datos
     */
    private function formulario(Request $request, array $contexto, array $datos): array
    {
        $sedes = $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
        $fecha = $datos['fecha'];
        $sede = $datos['sedeId'] !== null ? $sedes->firstWhere('id', (int) $datos['sedeId']) : null;
        $bloques = $sede ? $sede->bloquesDe($fecha->dayOfWeekIso) : collect();

        $horaEfectiva = $this->horaEfectiva(
            $datos['valores']['hora'] ?? null,
            $datos['valores']['bloque'] ?? null,
            $bloques,
        );

        // La jornada de esa sede ese día: la vista muestra con ella el cupo y el número que le toca
        // al próximo paciente, que es el mismo que se le va a asignar al guardar.
        $jornada = $sede
            ? $this->armador->jornadaPara($contexto['reg_medico'], $sedes, $fecha, $sede->medical_center_id, $horaEfectiva)
            : null;

        return [
            'cita'            => $datos['cita'],
            'accion'          => $datos['accion'],
            'sedes'           => $sedes,
            'sede'            => $sede,
            'fecha'           => $fecha,
            'bloques'         => $bloques,
            'jornada'         => $jornada,
            'horaEfectiva'    => $horaEfectiva,
            'motivos'         => $this->motivosDe($contexto, $sede),
            'pacienteElegido' => $datos['pacienteElegido'],
            'pendiente'       => $datos['pendiente'],
            'valores'         => $datos['valores'],
            'urlBuscar'       => route('clinica.pacientes.buscar'),
            // Día no laborable (WEB-2.8): el escritorio aborta el agendamiento, la web lo avisa y deja
            // decidir. El texto es el `motivo` del legado, tal cual.
            'noLaborable'     => $this->avisoDelDia($contexto, $fecha),
            // La marca es por médico: el aviso se arma con la clave del médico del contexto.
            'urlNoLaborables' => route('clinica.agenda.no-laborables'),
        ];
    }

    /**
     * Lo que se acepta del formulario. El paciente es **o** uno elegido del listado **o** una ficha
     * nueva con sus datos mínimos (cédula, nombres y apellidos: lo que identifica a la persona).
     *
     * @return array<string,array<int,mixed>>
     */
    private function reglas(): array
    {
        return [
            'sede'            => ['nullable', 'integer'],
            'fecha'           => ['required', 'date_format:Y-m-d'],
            'hora'            => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'bloque'          => ['nullable', 'integer', 'min:0'],
            'motivo'          => ['nullable', 'string', 'max:50'],
            'monto'           => ['nullable', 'numeric', 'min:0'],
            'paciente_id'     => ['nullable', 'integer'],
            'nuevo_cedula'    => ['nullable', 'required_without:paciente_id', 'string', 'max:20'],
            'nuevo_nombres'   => ['nullable', 'required_with:nuevo_cedula', 'string', 'max:100'],
            'nuevo_apellidos' => ['nullable', 'required_with:nuevo_cedula', 'string', 'max:100'],
            'nuevo_telefono'  => ['nullable', 'string', 'max:30'],
            'nuevo_email'     => ['nullable', 'email', 'max:120'],
            'nuevo_sexo'      => ['nullable', 'in:F,M'],
            'decision'        => ['nullable', 'in:mover,otra,cancelar'],
        ];
    }

    /**
     * La sede elegida, validada contra las del contexto: no se agenda en la sede de otro médico.
     * Si el médico todavía no tiene ninguna sede cargada, la cita nace sin sede — el estado de casi
     * todas las citas del legado—, pero con sedes configuradas hay que elegir una: es lo que define
     * la jornada y el lugar.
     */
    private function sedeElegida(Collection $sedes, $sedeId): ?Office
    {
        if ($sedes->isEmpty()) {
            return null;
        }

        $sede = $sedeId !== null ? $sedes->firstWhere('id', (int) $sedeId) : null;

        if ($sede === null) {
            throw ValidationException::withMessages(['sede' => 'Elegí la sede donde se atiende la cita.']);
        }

        return $sede;
    }

    /**
     * En una sede con **hora de cita** la hora es la cita; en una por **orden de llegada** la pone el
     * bloque (el paciente no elige hora). Misma distinción que el móvil.
     */
    private function exigirHoraSiEsConHora(?Office $sede, ?string $hora): void
    {
        if ($sede !== null && $sede->modalidad === Office::MODALIDAD_HORA && ($hora === null || $hora === '')) {
            throw ValidationException::withMessages(['hora' => 'Elegí la hora de la cita.']);
        }
    }

    /**
     * La hora con la que nace la cita: la elegida o, por orden de llegada, la de apertura del bloque
     * (el del índice elegido o el primero del día). Portado del móvil (`NuevaCitaScreen._horaIni`).
     */
    private function horaEfectiva(?string $hora, ?int $bloque, Collection $bloques): string
    {
        if ($hora !== null && $hora !== '') {
            return substr($hora, 0, 5);
        }

        $elegido = $bloque !== null ? $bloques->get($bloque) : null;
        $inicio = $elegido->hora_inicio ?? $bloques->first()->hora_inicio ?? self::HORA_POR_DEFECTO;

        return substr($inicio, 0, 5);
    }

    /**
     * Los motivos de cita que se pueden elegir en esa sede: los suyos primero y los que no tienen
     * sede (los que sube el escritorio, que no conoce el concepto de sede) como respaldo. Nunca se
     * repite un código: el `<select>` no admite dos opciones con el mismo valor.
     *
     * @return Collection<int,MotivoCita>
     */
    private function motivosDe(array $contexto, ?Office $sede): Collection
    {
        return MotivoCita::where('reg_medico', $contexto['reg_medico'])
            ->get()
            ->sortByDesc(fn (MotivoCita $motivo) => $motivo->office_id === $sede?->id)
            ->unique('codigo')
            ->sortBy('tipo_atencion')
            ->values();
    }

    /**
     * A quién se le está agendando, **sin escribir nada**: el paciente elegido del listado o la
     * ficha que ya exista con la cédula nueva (el alta enlaza, no duplica). La ficha nueva se crea
     * recién cuando la cita se guarda, para que cancelar no deje un paciente suelto — igual que el
     * móvil.
     *
     * @return array{paciente: ?Paciente, numhistoria: ?int}
     */
    private function identificarPaciente(array $contexto, array $valores): array
    {
        if (! empty($valores['paciente_id'])) {
            $paciente = $this->pacienteDelContexto($contexto, (int) $valores['paciente_id']);

            // De otro médico no se agenda desde acá (la búsqueda tampoco lo ofrece).
            abort_if($paciente === null, 404);

            return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
        }

        $cedula = trim((string) ($valores['nuevo_cedula'] ?? ''));
        if ($cedula === '') {
            throw ValidationException::withMessages([
                'paciente_id' => 'Elegí un paciente de la lista o cargá los datos de uno nuevo.',
            ]);
        }

        $paciente = Paciente::where('cedula', $cedula)->first();
        if ($paciente === null) {
            return ['paciente' => null, 'numhistoria' => null];
        }

        // La cédula ya existe: es la misma persona. Si ya es paciente de este médico, su número de
        // historia es el que va en la cita.
        return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
    }

    /** @return array<string,mixed> Las columnas del alta mínima de paciente (las mismas que el móvil). */
    private function columnasDelPacienteNuevo(array $valores): array
    {
        return [
            'cedula'    => trim((string) $valores['nuevo_cedula']),
            'nombres'   => trim((string) ($valores['nuevo_nombres'] ?? '')),
            'apellidos' => trim((string) ($valores['nuevo_apellidos'] ?? '')),
            'telefono'  => $this->texto($valores['nuevo_telefono'] ?? null),
            'email'     => $this->texto($valores['nuevo_email'] ?? null),
            'sexo'      => $this->texto($valores['nuevo_sexo'] ?? null),
        ];
    }

    /**
     * El monto de la cita: lo que se escribió o, si se dejó vacío, el precio del motivo elegido en
     * esa sede (el mismo que el formulario muestra como referencia). Sin ninguno de los dos queda
     * nulo, que es el estado de casi todas las citas del legado (ver `Cola::estadoPago()`).
     */
    private function montoDe(array $valores, array $contexto, ?Office $sede): ?float
    {
        if (isset($valores['monto']) && $valores['monto'] !== '') {
            return (float) $valores['monto'];
        }

        $codigo = $valores['motivo'] ?? null;
        if ($codigo === null || $codigo === '') {
            return null;
        }

        $precio = $this->motivosDe($contexto, $sede)->firstWhere('codigo', $codigo)?->precio;

        return $precio !== null ? (float) $precio : null;
    }

    /**
     * La fila de `cola` tal como nace: con la jornada ya resuelta (número, turno) y el paciente
     * anclado por su número de historia o, si todavía no tiene, por `paciente_sinhistoria_id`.
     *
     * @param  array{paciente: ?Paciente, numhistoria: ?int}  $objetivo
     */
    private function filaDeCita(
        array $objetivo,
        Paciente $paciente,
        Carbon $fecha,
        string $hora,
        Jornada $jornada,
        ?int $centroId,
        array $valores,
        array $contexto,
        ?float $monto
    ): array {
        $fila = [
            'fecha'             => $fecha->toDateString(),
            'hora_ini'          => $hora,
            'turno'             => Jornada::turnoDe($hora),
            'numorden'          => $jornada->siguienteNumero(),
            'medical_center_id' => $centroId,
            // La razón se guarda en `tipo`, que es donde la lee el escritorio. El `motivo` libre del
            // legado trae el nombre del paciente y la web no lo escribe.
            'tipo'              => $valores['motivo'] ?? null,
            'monto'             => $monto,
            'medico'            => $contexto['medico']->claveDeEvolucion($contexto['reg_medico']),
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ];

        if ($objetivo['numhistoria'] !== null) {
            $fila['numhistoria'] = $objetivo['numhistoria'];
        } else {
            $fila['paciente_sinhistoria_id'] = $paciente->id;
        }

        return $fila;
    }

    /**
     * Lo que cambia una cita al **moverla** a la fecha/hora/sede nuevas: la misma fila, con lo
     * cobrado intacto y el lugar recalculado solo si la jornada es otra.
     *
     * @param  array{cita: Cola, centroId: ?int}  $pendiente
     */
    private function cambiosDeMovimiento(
        array $pendiente,
        Collection $sedes,
        Carbon $fecha,
        string $hora,
        ?int $centroId,
        Jornada $jornada,
        ?string $motivo,
        ?float $monto
    ): array {
        $cita = $pendiente['cita'];
        $jornadaActual = $this->armador->jornadaPara(
            $cita->reg_medico,
            $sedes,
            Carbon::parse($cita->fecha),
            $pendiente['centroId'],
            $cita->hora_ini,
        );

        $cambios = [
            'fecha'             => $fecha->toDateString(),
            'hora_ini'          => $hora,
            'turno'             => Jornada::turnoDe($hora),
            'medical_center_id' => $centroId,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
            'sms_text'          => null,
        ];

        if ($jornadaActual->clave() !== $jornada->clave()) {
            $cambios['numorden'] = $jornada->siguienteNumero();
        }

        // Motivo y monto solo se pisan si se eligieron: mover una cita no es motivo para cambiarle
        // el precio (en el móvil es igual).
        if ($motivo !== null && $motivo !== '') {
            $cambios['tipo'] = $motivo;
        }
        if ($monto !== null) {
            $cambios['monto'] = $monto;
        }

        return $cambios;
    }

    /** Un paciente del `reg_medico` del contexto; de otro médico no se muestra ni se confirma que exista. */
    private function pacienteDelContexto(array $contexto, int $pacienteId): ?Paciente
    {
        $relacion = MedicoPaciente::where('medico_id', $contexto['medico']->id)
            ->where('paciente_id', $pacienteId)
            ->where('reg_medico', $contexto['reg_medico'])
            ->first();

        return $relacion !== null ? Paciente::find($relacion->paciente_id) : null;
    }

    private function numHistoriaDe(array $contexto, Paciente $paciente): ?int
    {
        $numhistoria = MedicoPaciente::where('medico_id', $contexto['medico']->id)
            ->where('paciente_id', $paciente->id)
            ->value('numhistoria');

        return $numhistoria !== null ? (int) $numhistoria : null;
    }

    /** La ficha del paciente de una cita: por `paciente_sinhistoria_id` o por el número de historia. */
    private function fichaDeLaCita(Cola $cita): ?Paciente
    {
        if ($cita->paciente_sinhistoria_id !== null) {
            return Paciente::find($cita->paciente_sinhistoria_id);
        }

        if ($cita->numhistoria === null) {
            return null;
        }

        $pacienteId = MedicoPaciente::where('reg_medico', $cita->reg_medico)
            ->where('numhistoria', $cita->numhistoria)
            ->value('paciente_id');

        return $pacienteId !== null ? Paciente::find($pacienteId) : null;
    }

    /**
     * La ficha del paciente elegido, para mostrarla en el formulario. Si todavía no se guardó (alta
     * nueva) se arma con lo que se escribió, para que la repregunta por la cita pendiente no la
     * pierda de vista.
     *
     * @return array{id: ?int, nombre: string, numhistoria: ?int}|null
     */
    private function fichaDe(?Paciente $paciente, ?int $numhistoria, array $valores = []): ?array
    {
        if ($paciente !== null) {
            return [
                'id'          => $paciente->id,
                'nombre'      => trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', '),
                'numhistoria' => $numhistoria,
            ];
        }

        $nombres = trim((string) ($valores['nuevo_nombres'] ?? ''));
        $apellidos = trim((string) ($valores['nuevo_apellidos'] ?? ''));
        if ($nombres === '' && $apellidos === '') {
            return null;
        }

        return ['id' => null, 'nombre' => trim($apellidos . ', ' . $nombres, ', '), 'numhistoria' => null];
    }

    /**
     * Volver a la agenda en el día y la sede de la cita, que es donde el usuario espera verla.
     *
     * `$avisos` son los mensajes que **no** son el resultado de la operación (hoy, el día no
     * laborable): viajan en una lista para que no se pisen con `estado` ni entre ellos.
     *
     * @param  array<int,string>  $avisos
     */
    private function volverALaAgenda(Carbon $fecha, ?Office $sede, ?string $mensaje, array $avisos = [])
    {
        $destino = redirect()->route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $fecha->toDateString(),
            'sede'  => $sede?->id ?? 'todas',
        ]);

        if ($avisos !== []) {
            $destino = $destino->with('avisos', $avisos);
        }

        return $mensaje !== null ? $destino->with('estado', $mensaje) : $destino;
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
