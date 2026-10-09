<?php

namespace App\Clinica\Agenda;

use App\Models\Cola;
use Carbon\Carbon;

/**
 * Una cita de la agenda, ya leída y lista para pintar (PLAN-WEB.md, F2).
 *
 * Es a propósito un objeto plano y no el modelo `Cola`: la vista necesita cosas **calculadas** —el
 * estado de pago derivado, si se puede confirmar— y el modelo del legado no las tiene ni debería
 * tenerlas. Las reglas están **portadas del móvil** (`lib/features/agenda/data/models/cola.dart`,
 * `Cola.estadoPago`/`puedeConfirmar`/`puedeAtender`) para que la web muestre exactamente lo mismo
 * que el teléfono sobre la misma base.
 *
 * Confirmación, atención y pago son **tres dimensiones independientes**, no un estado único: una
 * cita puede estar confirmada, pagada y sin atender al mismo tiempo.
 */
final class CitaDeAgenda
{
    public function __construct(
        public readonly int $id,
        public readonly string $fecha,              // Y-m-d
        public readonly ?string $horaIni,           // tal como viene del legado (H:i:s o null)
        public readonly ?int $numOrden,
        public readonly ?int $estado,
        public readonly ?int $atendido,
        public readonly ?float $monto,
        public readonly ?float $montoPagado,
        public readonly ?int $numHistoria,
        public readonly ?int $pacienteSinHistoriaId,
        public readonly ?int $pacienteId,          // `pacientes.id`, para enlazar a la ficha
        public readonly string $paciente,
        public readonly ?int $centroId,             // sede resuelta (cola.medical_center_id o la historia)
        public readonly ?string $motivo,
        public readonly ?string $tipo,
        public readonly ?int $medico,
        /**
         * El escritorio PowerBuilder la **movió a otro día** (`cola.movida_escritorio`; en su
         * `atendido` era un `2`, WEB-2.13). Acá se muestra marcada, sin acciones y fuera del cupo:
         * el escritorio la esconde del día, pero la web no pierde el dato.
         */
        public readonly bool $movidaEscritorio = false,
        /**
         * La razón de la cita **resuelta contra el catálogo** (`motivo_cita.tipo_atencion`), o nula
         * si el código no está en el catálogo. Ver `razon()`.
         */
        public readonly ?string $motivoNombre = null,
    ) {
    }

    /** La confirmó el consultorio (1) o el paciente (2). Ver `Cola::ESTADO_*`. */
    public function confirmada(): bool
    {
        return in_array((int) $this->estado, [Cola::ESTADO_CONFIRMADA, Cola::ESTADO_CONFIRMADA_PACIENTE], true);
    }

    public function atendida(): bool
    {
        return (int) $this->atendido === 1;
    }

    /** El escritorio la postergó: esta fila es la vieja, la nueva está en la fecha destino. */
    public function movida(): bool
    {
        return $this->movidaEscritorio;
    }

    public function montoPendiente(): float
    {
        return max(0.0, (float) ($this->monto ?? 0) - (float) ($this->montoPagado ?? 0));
    }

    /** Estado de pago **derivado** de los montos, no guardado (`Cola::estadoPago()`). */
    public function estadoPago(): string
    {
        $monto = (float) ($this->monto ?? 0);
        $pagado = (float) ($this->montoPagado ?? 0);

        if ($monto <= 0) {
            return $pagado > 0 ? Cola::PAGO_PAGADA : Cola::PAGO_SIN_MONTO;
        }

        if ($pagado >= $monto) {
            return Cola::PAGO_PAGADA;
        }

        return $pagado > 0 ? Cola::PAGO_ABONADA : Cola::PAGO_PENDIENTE;
    }

    /**
     * "Confirmar" en el consultorio significa **que el paciente llegó**: aparece recién una hora
     * antes de la cita y solo ese día (aclaración de Alexander, portada del móvil). Es una ayuda de
     * interfaz —la Action no la exige— para no ofrecer un botón que no tiene sentido todavía.
     */
    public function puedeConfirmar(Carbon $ahora): bool
    {
        return ! $this->movida()
            && ! $this->confirmada()
            && $this->esDelDia($ahora)
            && ! $ahora->lessThan($this->inicio()->subHour());
    }

    /**
     * "Atender" solo el día de la cita. Una vez atendida no hay vuelta atrás.
     *
     * Una cita movida por el escritorio tampoco se atiende: esta fila es la vieja y el paciente ya
     * está en su fecha nueva.
     */
    public function puedeAtender(Carbon $ahora): bool
    {
        return ! $this->movida() && ! $this->atendida() && $this->esDelDia($ahora);
    }

    public function horaCorta(): string
    {
        return $this->horaIni ? substr($this->horaIni, 0, 5) : '—';
    }

    /**
     * La razón de la cita, resuelta contra el catálogo de motivos.
     *
     * **No se usa `cola.motivo` como si fuera la razón**: en el legado esa columna es texto libre y
     * en los datos reales trae el nombre del paciente (`'ATENDER A: …'`, R10 del contraste). La
     * razón de verdad es el `codigo` de `motivo_cita`, que el escritorio guarda en `cola.tipo` y el
     * móvil en `cola.motivo` — por eso se buscan los dos. El texto libre solo se muestra si parece
     * un código y no está en el catálogo (último recurso: es lo único cierto que hay de esa fila).
     */
    public function razon(): string
    {
        if ($this->motivoNombre !== null && $this->motivoNombre !== '') {
            return $this->motivoNombre;
        }

        if ($this->tipo !== null && $this->tipo !== '') {
            return $this->tipo;
        }

        return $this->pareceCodigo($this->motivo) ? trim((string) $this->motivo) : '—';
    }

    /** ¿El texto parece un código del catálogo y no el nombre del paciente? */
    private function pareceCodigo(?string $texto): bool
    {
        return $texto !== null && preg_match('/^[A-Za-z0-9._-]{1,12}$/', trim($texto)) === 1;
    }

    public function inicio(): Carbon
    {
        $fecha = Carbon::parse($this->fecha);
        $hora = $this->horaIni ? explode(':', $this->horaIni) : [];

        return $fecha->setTime((int) ($hora[0] ?? 0), (int) ($hora[1] ?? 0));
    }

    public function esDelDia(Carbon $dia): bool
    {
        return $this->fecha === $dia->toDateString();
    }

    /** Texto de la etiqueta de pago, con el monto cuando lo hay. */
    public function etiquetaPago(): string
    {
        return match ($this->estadoPago()) {
            Cola::PAGO_PAGADA     => 'Pagado',
            Cola::PAGO_ABONADA    => 'Abonado ' . $this->money((float) $this->montoPagado),
            Cola::PAGO_PENDIENTE  => 'Debe ' . $this->money($this->montoPendiente()),
            default               => 'Sin monto',
        };
    }

    private function money(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
