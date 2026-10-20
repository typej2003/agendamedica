<?php

namespace App\Clinica\Agenda;

use App\Actions\Agenda\CrearCita;
use App\Actions\Agenda\EditarCita;
use App\Actions\Pacientes\AsignarHistoria;
use App\Actions\Pacientes\CrearPaciente;
use App\Models\Cola;
use App\Models\Office;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Agendar y reagendar una cita desde la web clínica (el alta y la edición del mostrador).
 *
 * Aplica las reglas del móvil sobre las **Actions compartidas** (`CrearCita`, `EditarCita`, `CrearPaciente`):
 * ninguna regla de negocio de la cita se escribe dos veces (PLAN-WEB.md, R3). Lo que decide acá es la
 * secuencia: a quién se le agenda, si ya tiene una cita pendiente (se pregunta antes de escribir nada),
 * en qué jornada cae y con qué hora, número y monto nace la fila.
 *
 * La regla de la cita pendiente es la de Alexander: un paciente tiene **una** cita pendiente por médico y
 * consultorio. La excepción de los tratamientos (masajistas, terapias, donde sí se agendan varias) se
 * resuelve con la marca del motivo (`motivo_cita.admite_varias_citas_pendientes`, WEB-2.17): con el
 * motivo marcado no se pregunta nada.
 */
class AgendamientoDeCitas
{
    /**
     * Hora con la que nace una cita cuando la sede no tiene bloques configurados: `cola.hora_ini` es
     * obligatoria en el esquema legado (el listado la muestra y ordena por ella).
     */
    private const HORA_POR_DEFECTO = '08:00';

    public function __construct(
        private ArmadorDeAgenda $armador,
        private MotivosDeCita $motivos,
        private PacientesDeCita $pacientes,
        private CrearCita $crear,
        private CrearPaciente $crearPaciente,
        private AsignarHistoria $asignarHistoria,
        private EditarCita $editar,
    ) {
    }

    /**
     * Agendar una cita nueva.
     *
     * Si el paciente **ya tiene una cita pendiente** en ese consultorio no se escribe nada todavía: se
     * devuelve la pregunta —mover esa cita a la fecha nueva, agendar otra de todos modos o cancelar—, que
     * es lo que hace el móvil. Con un motivo que **admite varias citas pendientes** la pregunta no
     * existe: se agenda directo (WEB-2.17).
     *
     * @param  array<string,mixed>  $valores  lo validado del formulario
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico. **Ya no decide el número de
     *                                               historia**: desde la Fase 1 del cambio de `reg_medico` el
     *                                               correlativo es el del **consultorio de la cita**
     *                                               (`$contexto['reg_medico']`). Se conserva en la firma porque
     *                                               es la que comparten el mostrador, el portal y el móvil.
     * @param  array{numerar_historia?: bool, estado?: int}  $opciones
     *                                               El portal público de reservas (2026-10-19) agenda sin
     *                                               numerar la historia —el número lo asigna el consultorio
     *                                               cuando el paciente llega— y con la cita ya confirmada por
     *                                               el paciente. La web clínica no manda opciones: conserva
     *                                               el alta con historia y la cita sin confirmar.
     */
    public function agendar(array $contexto, array $valores, array $registrosMedicos, array $opciones = []): ResultadoDeAgendamiento
    {
        $regMedico = $contexto['reg_medico'];
        $numerarHistoria = $opciones['numerar_historia'] ?? true;
        $estado = (int) ($opciones['estado'] ?? Cola::ESTADO_NO_CONFIRMADA);

        $sedes = $this->armador->sedes($regMedico);
        $sede = $this->sedeElegida($sedes, $valores['sede'] ?? null);
        $fecha = Carbon::createFromFormat('!Y-m-d', $valores['fecha']);
        $bloques = $sede ? $sede->bloquesDe($fecha->dayOfWeekIso) : collect();
        $this->exigirHoraSiEsConHora($sede, $valores['hora'] ?? null);
        $hora = $this->horaEfectiva($valores['hora'] ?? null, $valores['bloque'] ?? null, $bloques);
        $centroId = $sede?->medical_center_id;

        // A quién se le agenda, **sin escribir nada todavía**: el paciente elegido, o la ficha que ya
        // exista con la cédula nueva (el alta enlaza, no duplica).
        $objetivo = $this->pacientes->identificar($contexto, $valores);

        // La excepción de las citas múltiples (WEB-2.17): si el motivo elegido **admite varias citas
        // pendientes** del mismo paciente —masajistas, terapias—, la regla de la cita única no aplica y
        // no hay nada que preguntar. La marca es del motivo, y el formulario guarda su código en
        // `cola.tipo` (WEB-2.16), así que se resuelve por el código elegido; sin motivo, la regla común
        // se aplica igual que siempre.
        $admiteVarias = $this->motivos->admiteVariasCitas($regMedico, $valores['motivo'] ?? null, $sede);
        $pendiente = $admiteVarias
            ? null
            : $this->armador->citaPendiente($regMedico, $objetivo['numhistoria'], $objetivo['paciente']?->id, $centroId);
        $decision = $valores['decision'] ?? null;

        if ($pendiente !== null && $decision === null) {
            return new ResultadoDeAgendamiento(ResultadoDeAgendamiento::PREGUNTA, $fecha, $sede, $valores, $objetivo, $pendiente);
        }

        if ($decision === 'cancelar') {
            return new ResultadoDeAgendamiento(ResultadoDeAgendamiento::CANCELADO, $fecha, $sede);
        }

        // La ficha y su **vínculo con el médico** los garantiza la Action: si la cédula ya existía, la
        // enlaza (completando solo lo que estaba vacío) en vez de duplicarla. Cuando el paciente se
        // eligió del listado ya está enlazado y no hay nada que crear; y la ficha nueva se crea recién
        // acá, para que cancelar la pregunta no deje un paciente suelto.
        if (! empty($valores['paciente_id'])) {
            $paciente = $objetivo['paciente'];
        } else {
            $paciente = $this->crearPaciente->ejecutar($this->pacientes->columnasDelNuevo($valores), $contexto['medico'], $regMedico);

            // **El alta rápida del paso 2 numera la historia en el mismo paso** (O-04, 2026-10-17): es un
            // alta **desde la web** (hay consultorio de contexto), así que el paciente queda atendible de
            // inmediato y la cita nace enlazada por `numhistoria`. El número lo sigue dando
            // `AsignarHistoria`, la única regla del correlativo; el chatbot y el móvil, que crean por
            // `CrearPaciente` sin contexto, siguen sin número. El portal público tampoco numera: la cita
            // queda anclada por `paciente_sinhistoria_id` y el consultorio numera cuando el paciente llega.
            if ($numerarHistoria) {
                // El número es **del consultorio de la cita** (`$regMedico`), que es el tenant del vínculo.
                $historia = $this->asignarHistoria->ejecutar($contexto['medico'], $regMedico, (int) $paciente->getKey());
                $objetivo = ['paciente' => $paciente, 'numhistoria' => (int) $historia->numhistoria];
            } else {
                $objetivo = ['paciente' => $paciente, 'numhistoria' => null];
            }
        }

        $jornada = $this->armador->jornadaPara($regMedico, $sedes, $fecha, $centroId, $hora);
        $monto = $this->montoDe($valores, $contexto, $sede);

        // "Mover esa cita a la nueva fecha": es **la misma fila** movida, no una cita nueva (conserva lo
        // cobrado), así que la cita pendiente se edita y no se crea nada.
        if ($decision === 'mover' && $pendiente !== null) {
            $this->editar->ejecutar(
                $pendiente['cita'],
                $this->cambiosDeMovimiento($pendiente, $sedes, $fecha, $hora, $centroId, $jornada, $valores['motivo'] ?? null, $monto),
                Carbon::now(),
                'web',
            );

            return new ResultadoDeAgendamiento(ResultadoDeAgendamiento::MOVIDA, $fecha, $sede);
        }

        $cita = $this->crear->ejecutar(
            $regMedico,
            $this->filaDeCita($objetivo, $paciente, $fecha, $hora, $jornada, $centroId, $valores, $contexto, $monto, $estado),
            Carbon::now(),
            'web',
        );

        return new ResultadoDeAgendamiento(ResultadoDeAgendamiento::CREADA, $fecha, $sede, objetivo: $objetivo, cita: $cita);
    }

    /**
     * Reagendar es editar la misma fila (wiki `02-modulo-agenda.md` §4). El paciente no se toca: cambiar
     * de paciente es otra acción, sin reglas definidas todavía.
     *
     * @param  array<string,mixed>  $valores  lo validado del formulario
     */
    public function reagendar(array $contexto, Cola $cita, array $valores): ResultadoDeAgendamiento
    {
        $regMedico = $contexto['reg_medico'];

        $sedes = $this->armador->sedes($regMedico);
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
        // reinicia: la confirmación y la constancia del recordatorio (el paciente ya recibió el mensaje
        // de otro día). Es lo que hace el móvil al reagendar.
        if ($jornadaActual->clave() !== $jornada->clave()) {
            $cambios['numorden'] = $jornada->siguienteNumero();
            $cambios['estado'] = Cola::ESTADO_NO_CONFIRMADA;
            $cambios['sms_text'] = null;
        }

        $aplicadas = $this->editar->ejecutar($cita, $cambios, Carbon::now(), 'web');

        return new ResultadoDeAgendamiento(
            $aplicadas === [] ? ResultadoDeAgendamiento::SIN_CAMBIOS : ResultadoDeAgendamiento::ACTUALIZADA,
            $fecha,
            $sede,
        );
    }

    // -------------------------------------------------------------------- Reglas de la sede y la hora

    /**
     * La sede elegida, validada contra las del contexto: no se agenda en la sede de otro médico. Si el
     * médico todavía no tiene ninguna sede cargada, la cita nace sin sede —el estado de casi todas las
     * citas del legado—, pero con sedes configuradas hay que elegir una: es lo que define la jornada y el
     * lugar.
     *
     * @param  Collection<int,Office>  $sedes
     */
    public function sedeElegida(Collection $sedes, $sedeId): ?Office
    {
        if ($sedes->isEmpty()) {
            return null;
        }

        $sede = $sedeId !== null ? $sedes->firstWhere('id', (int) $sedeId) : null;

        if ($sede === null) {
            throw ValidationException::withMessages(['sede' => 'Elige la sede donde se atiende la cita.']);
        }

        return $sede;
    }

    /**
     * En una sede con **hora de cita** la hora es la cita; en una por **orden de llegada** la pone el
     * bloque (el paciente no elige hora). Misma distinción que el móvil.
     */
    public function exigirHoraSiEsConHora(?Office $sede, ?string $hora): void
    {
        if ($sede !== null && $sede->modalidad === Office::MODALIDAD_HORA && ($hora === null || $hora === '')) {
            throw ValidationException::withMessages(['hora' => 'Elige la hora de la cita.']);
        }
    }

    /**
     * La hora con la que nace la cita: la elegida o, por orden de llegada, la de apertura del bloque (el
     * del índice elegido o el primero del día). Portado del móvil (`NuevaCitaScreen._horaIni`).
     */
    public function horaEfectiva(?string $hora, ?int $bloque, Collection $bloques): string
    {
        if ($hora !== null && $hora !== '') {
            return substr($hora, 0, 5);
        }

        $elegido = $bloque !== null ? $bloques->get($bloque) : null;
        $inicio = $elegido->hora_inicio ?? $bloques->first()->hora_inicio ?? self::HORA_POR_DEFECTO;

        return substr($inicio, 0, 5);
    }

    // ------------------------------------------------------------------------- Cómo nace la fila

    /**
     * El monto de la cita: lo que se escribió o, si se dejó vacío, el precio del motivo elegido en esa
     * sede (el mismo que el formulario muestra como referencia). Sin ninguno de los dos queda nulo, que es
     * el estado de casi todas las citas del legado (ver `Cola::estadoPago()`).
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

        $precio = $this->motivos->de($contexto['reg_medico'], $sede)->firstWhere('codigo', $codigo)?->precio;

        return $precio !== null ? (float) $precio : null;
    }

    /**
     * La fila de `cola` tal como nace: con la jornada ya resuelta (número, turno) y el paciente anclado por
     * su número de historia o, si todavía no tiene, por `paciente_sinhistoria_id`.
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
        ?float $monto,
        int $estado = Cola::ESTADO_NO_CONFIRMADA
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
            // La `clave` legacy de `cola.medico` es la del **doctor**, y se busca en **sus** registros (el
            // suyo y los centros donde trabaja), no en el `reg_medico` del contexto: la fila de `evolucion`
            // del médico vive bajo su registro y en un centro el `reg_medico` del contexto no la tiene
            // (Fase 2 del cambio de `reg_medico`). El `medico_id` de la cita es otra fase.
            'medico'            => $contexto['medico']->claveDeEvolucion($contexto['medico']->registrosDeTrabajo()),
            'atendido'          => 0,
            'estado'            => $estado,
        ];

        if ($objetivo['numhistoria'] !== null) {
            $fila['numhistoria'] = $objetivo['numhistoria'];
        } else {
            $fila['paciente_sinhistoria_id'] = $paciente->id;
        }

        return $fila;
    }

    /**
     * Lo que cambia una cita al **moverla** a la fecha/hora/sede nuevas: la misma fila, con lo cobrado
     * intacto y el lugar recalculado solo si la jornada es otra.
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

        // Motivo y monto solo se pisan si se eligieron: mover una cita no es motivo para cambiarle el
        // precio (en el móvil es igual).
        if ($motivo !== null && $motivo !== '') {
            $cambios['tipo'] = $motivo;
        }
        if ($monto !== null) {
            $cambios['monto'] = $monto;
        }

        return $cambios;
    }
}
