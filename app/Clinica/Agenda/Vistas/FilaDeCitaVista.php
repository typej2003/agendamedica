<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Models\Cola;
use Carbon\Carbon;

/**
 * Una cita de la agenda tal como se ve en su fila: el turno, el paciente, los dos estados (cita y pago) y
 * las acciones que se ofrecen. Cada acción lleva su URL y lo que el diálogo o el botón necesitan; **qué
 * se ofrece** lo decide `CitaDeAgenda` (portado del móvil), esta clase solo lo traduce a lo que se pinta.
 */
final class FilaDeCitaVista
{
    public function __construct(
        private CitaDeAgenda $cita,
        private ?int $posicion,
        private bool $arrastrable,
        private Carbon $ahora,
        private string $mensaje = '',
        /**
         * Si el usuario que mira la agenda **puede entrar a la consulta** (WEB-4.1: la consulta es sólo
         * del médico, PLAN-WEB.md §3.14). Lo resuelve el controlador con la misma regla que la ruta del
         * shell —`User::trabajaLaConsulta()`— para que la fila no ofrezca una acción que le responde 403
         * (WEB-4.9: "Atender" no se le muestra a la secretaría, ni como botón ni en el menú).
         */
        private bool $puedeAbrirLaConsulta = true,
    ) {
    }

    public function id(): int
    {
        return $this->cita->id;
    }

    /**
     * "#7": la posición calculada sobre todo el rango. En la pantalla de un paciente (`$posicion` nula
     * porque no hay jornada a la vista) queda vacío; en la agenda, la cita que el escritorio movió
     * muestra "—" con el porqué en el `title`.
     */
    public function turno(): string
    {
        if ($this->posicion !== null) {
            return '#' . $this->posicion;
        }

        return $this->movida() ? '—' : '';
    }

    public function tieneTurno(): bool
    {
        return $this->posicion !== null;
    }

    public function hora(): string
    {
        return $this->cita->horaCorta();
    }

    /** La fecha `Y-m-d` de la cita: la pantalla de un paciente la formatea con `FechaClinica`. */
    public function fecha(): string
    {
        return $this->cita->fecha;
    }

    public function estaConfirmada(): bool
    {
        return $this->cita->confirmada();
    }

    /** `Cola::PAGO_*`: el mismo estado derivado que pinta la columna de pago de la agenda. */
    public function estadoPago(): string
    {
        return $this->cita->estadoPago();
    }

    public function paciente(): string
    {
        return $this->cita->paciente;
    }

    public function urlPaciente(): ?string
    {
        return $this->cita->pacienteId ? route('clinica.pacientes.ver', $this->cita->pacienteId) : null;
    }

    /** `pacientes.id` del paciente de la cita, para el formulario que la elimina. */
    public function pacienteId(): ?int
    {
        return $this->cita->pacienteId;
    }

    /** "H. 4001", o `null` si el paciente todavía no tiene número de historia. */
    public function historia(): ?string
    {
        return $this->cita->numHistoria ? 'H. ' . $this->cita->numHistoria : null;
    }

    public function razon(): string
    {
        return $this->cita->razon();
    }

    /** Lo que va debajo del nombre: la historia y la razón de la cita. */
    public function detalle(): string
    {
        return collect([$this->historia(), $this->razon() !== '—' ? $this->razon() : null])->filter()->implode(' · ');
    }

    public function movida(): bool
    {
        return $this->cita->movida();
    }

    public function atendida(): bool
    {
        return $this->cita->atendida();
    }

    /** La clase de la fila: la movida se atenúa y la atendida se tiñe de verde suave. */
    public function clase(): string
    {
        if ($this->movida()) {
            return 'fila-movida';
        }

        return $this->atendida() ? 'fila-atendida' : '';
    }

    /** Solo se arrastra donde el orden lo decide la cola, y nunca una cita movida. */
    public function arrastrable(): bool
    {
        return $this->arrastrable && ! $this->movida();
    }

    public function numOrden(): ?int
    {
        return $this->cita->numOrden;
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:?string} */
    public function estado(): array
    {
        if ($this->movida()) {
            return ['tono' => 'neutro', 'icono' => 'bi-arrow-right-circle', 'texto' => 'Movida',
                'titulo' => 'El escritorio la postergó: el paciente ya está en la fecha nueva'];
        }

        if ($this->atendida()) {
            return ['tono' => 'exito', 'icono' => 'bi-check2-all', 'texto' => 'Atendida', 'titulo' => null];
        }

        if ($this->cita->confirmada()) {
            return ['tono' => 'marca', 'icono' => 'bi-check2-circle', 'texto' => 'Confirmada', 'titulo' => null];
        }

        return ['tono' => 'neutro', 'icono' => 'bi-hourglass-split', 'texto' => 'Sin confirmar', 'titulo' => null];
    }

    /** @return array{tono:string, icono:string, texto:string} */
    public function pago(): array
    {
        $texto = $this->cita->etiquetaPago();

        return match ($this->cita->estadoPago()) {
            Cola::PAGO_PAGADA    => ['tono' => 'exito', 'icono' => 'bi-cash-coin', 'texto' => $texto],
            Cola::PAGO_SIN_MONTO => ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => $texto],
            default              => ['tono' => 'aviso', 'icono' => 'bi-cash', 'texto' => $texto],
        };
    }

    // ------------------------------------------------------------------------------------ Acciones

    /**
     * El recordatorio (WEB-2.6): desaparece si la cita ya está confirmada o atendida —no se le recuerda a
     * quien ya confirmó— y avisa qué dato le falta al paciente. El diálogo es uno solo para la página.
     *
     * @return array<string,mixed>|null
     */
    public function recordatorio(): ?array
    {
        if (! $this->cita->puedeRecordar()) {
            return null;
        }

        return [
            'url'           => route('clinica.agenda.notificar', $this->cita->id),
            'paciente'      => $this->cita->paciente,
            'telefono'      => $this->cita->telefono,
            'email'         => $this->cita->email,
            'mensaje'       => $this->mensaje,
            'tieneTelefono' => $this->cita->tieneTelefono(),
            'tieneCorreo'   => $this->cita->tieneCorreo(),
        ];
    }

    /**
     * "Confirmar" significa que el paciente llegó: solo el día de la cita, desde una hora antes. Una cita ya
     * atendida no lo ofrece: atender implica confirmada (aunque en los datos del legado no lo esté).
     */
    public function confirmar(): ?array
    {
        if ($this->movida() || $this->atendida() || $this->cita->confirmada()) {
            return null;
        }

        $puede = $this->cita->puedeConfirmar($this->ahora);

        return [
            'url'       => route('clinica.agenda.confirmar', $this->cita->id),
            'estado'    => Cola::ESTADO_CONFIRMADA,
            'habilitada' => $puede,
            'titulo'    => $puede ? 'El paciente llegó' : 'Se confirma el día de la cita, desde una hora antes',
        ];
    }

    public function quitarConfirmacion(): ?array
    {
        if ($this->movida() || ! $this->cita->confirmada() || $this->atendida()) {
            return null;
        }

        return [
            'url'    => route('clinica.agenda.confirmar', $this->cita->id),
            'estado' => Cola::ESTADO_NO_CONFIRMADA,
        ];
    }

    /**
     * **"Atender" abre la consulta** (WEB-4.1): abre —o crea— la consulta del día y deja la cita
     * atendida, todo en el mismo POST. Solo el día de la cita, y una cita ya atendida no se vuelve a
     * marcar: con la cita atendida el botón dice **"Editar consulta"** y entra a la misma consulta del
     * día sin tocar nada (wiki `04-historia-clinica.md`).
     *
     * **A la secretaría no se le ofrece —ni como botón ni en el menú—** (WEB-4.9, decisión del usuario
     * del 2026-10-11): "Atender" es abrir la consulta, y la consulta es del médico. No es un botón
     * deshabilitado con un motivo: la acción no está entre las suyas, así que la fila no la pinta (y el
     * POST a mano responde 403). Lo que sí conserva —confirmar, cobrar, agendar y reordenar— sale de los
     * otros métodos de esta misma clase.
     */
    public function atender(): ?array
    {
        if ($this->movida() || ! $this->puedeAbrirLaConsulta) {
            return null;
        }

        if ($this->atendida()) {
            return [
                'url'       => route('clinica.agenda.atender', $this->cita->id),
                'editar'    => true,
                'habilitada' => true,
                'titulo'    => 'Abrir la consulta de este día',
            ];
        }

        $puede = $this->cita->puedeAtender($this->ahora);

        return [
            'url'       => route('clinica.agenda.atender', $this->cita->id),
            'editar'    => false,
            'habilitada' => $puede,
            'titulo'    => $puede
                ? 'Abrir la consulta del día y marcar la cita como atendida (implica confirmada)'
                : 'Atender abre la consulta: solo el día de la cita',
        ];
    }

    /** El cobro: abono parcial sumado a lo ya pagado. El monto solo se pide si la cita no tiene precio. */
    public function cobro(): ?array
    {
        if ($this->movida() || $this->cita->estadoPago() === Cola::PAGO_PAGADA) {
            return null;
        }

        return [
            'url'           => route('clinica.agenda.cobrar', $this->cita->id),
            'paciente'      => $this->cita->paciente,
            'pendiente'     => number_format($this->cita->montoPendiente(), 2, '.', ''),
            'requiereMonto' => $this->cita->estadoPago() === Cola::PAGO_SIN_MONTO,
            'detalle'       => $this->cita->etiquetaPago(),
        ];
    }

    /** Una cita atendida ya ocurrió (y tiene la consulta colgando): no se edita. */
    public function urlEditar(): ?string
    {
        return $this->movida() || $this->atendida() ? null : route('clinica.agenda.editar', $this->cita->id);
    }

    /**
     * Una cita con pago registrado no se borra: la regla la aplica `EliminarCita`; acá solo no se
     * ofrece el botón.
     */
    public function urlEliminar(): ?string
    {
        if ($this->movida() || $this->atendida()) {
            return null;
        }

        $conPago = in_array($this->cita->estadoPago(), [Cola::PAGO_PAGADA, Cola::PAGO_ABONADA], true);

        return $conPago ? null : route('clinica.agenda.eliminar', $this->cita->id);
    }
}
