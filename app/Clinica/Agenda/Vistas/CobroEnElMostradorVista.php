<?php

namespace App\Clinica\Agenda\Vistas;

use App\Actions\Pagos\CobrarEnElMostrador;
use App\Models\Cola;
use App\Models\Pago;
use App\Pagos\Centavos;
use App\Support\FechaClinica;
use App\Support\HoraLocal;

/**
 * **La pantalla para cobrar una cita en el mostrador** (O-53): lo que falta cobrar, los dos caminos
 * —pasarela o registro manual— y lo que ya se cobró de esa cita.
 *
 * No decide nada del cobro: la Action `CobrarEnElMostrador` ya comprobó si está pagada y creó o registró
 * el pago. Acá sólo se traduce a texto y URLs.
 */
final class CobroEnElMostradorVista
{
    /** @param \Illuminate\Database\Eloquent\Collection<int,Pago> $pagos */
    public function __construct(
        private readonly Cola $cita,
        private readonly \Illuminate\Database\Eloquent\Collection $pagos,
        private readonly bool $hayMetodo,
        private readonly ?string $nombreMetodo = null,
        private readonly ?string $medicoNombre = null,
    ) {
    }

    public function titulo(): string
    {
        return 'Cobrar la cita';
    }

    public function subtitulo(): string
    {
        return implode(' · ', array_filter([
            $this->paciente(),
            'Cita del ' . $this->fecha() . ($this->hora() !== '' ? ' a las ' . $this->hora() : ''),
            $this->medicoNombre,
        ]));
    }

    public function paciente(): string
    {
        $paciente = $this->cita->paciente;

        return trim((string) ($paciente?->nombres ?? '') . ' ' . (string) ($paciente?->apellidos ?? ''));
    }

    public function fecha(): string
    {
        return FechaClinica::formato($this->cita->fecha, 'd/m/Y');
    }

    public function hora(): string
    {
        return HoraLocal::reloj($this->cita->hora_ini, '');
    }

    public function monto(): string
    {
        return $this->usd((float) ($this->cita->monto ?? 0));
    }

    public function pagado(): string
    {
        return $this->usd((float) ($this->cita->monto_pagado ?? 0));
    }

    public function pendiente(): string
    {
        return $this->usd($this->cita->montoPendiente());
    }

    /** El pendiente como número del formulario (`50.00`), que es lo que se propone cobrar. */
    public function pendienteParaElFormulario(): string
    {
        return number_format($this->cita->montoPendiente(), 2, '.', '');
    }

    /** ¿La cita ya está cobrada? Entonces no se ofrece ningún camino. */
    public function estaPagada(): bool
    {
        if ($this->cita->estadoPago() === Cola::PAGO_PAGADA) {
            return true;
        }

        return $this->pagos->contains(fn (Pago $pago) => $pago->estaAprobado());
    }

    /** ¿La cita tiene precio? Sin precio no hay nada que cobrar hasta que se lo pongan. */
    public function sinPrecio(): bool
    {
        return (float) ($this->cita->monto ?? 0) <= 0;
    }

    /** ¿Se puede cobrar con la pasarela? Hace falta un método configurado y un saldo. */
    public function puedeUsarLaPasarela(): bool
    {
        return $this->hayMetodo && ! $this->estaPagada() && ! $this->sinPrecio();
    }

    public function nombreDelMetodo(): string
    {
        return (string) ($this->nombreMetodo ?? '');
    }

    /** El pago que está esperando el código: es el que la pantalla deja confirmar. */
    public function pagoEnProceso(): ?Pago
    {
        return $this->pagos->first(fn (Pago $pago) => $pago->estaEnProceso());
    }

    /** Los medios del cobro manual, para los botones de radio. */
    public function medios(): array
    {
        return CobrarEnElMostrador::MEDIOS;
    }

    /** @return array<int,array{id:int, monto:string, estado:string, tono:string, fecha:string, medio:string, canal:string}> */
    public function pagos(): array
    {
        return $this->pagos->map(function (Pago $pago): array {
            return [
                'id' => (int) $pago->id,
                'monto' => Centavos::formatear((int) $pago->monto_usd_e2, 'USD'),
                'estado' => $this->estadoDe($pago),
                'tono' => match ($pago->estado) {
                    Pago::APROBADO => 'exito',
                    Pago::RECHAZADO, Pago::CANCELADO, Pago::EXPIRADO, Pago::REEMBOLSADO => 'error',
                    default => 'aviso',
                },
                'fecha' => $pago->pagado_el
                    ? FechaClinica::formato($pago->pagado_el, 'd/m/Y H:i')
                    : FechaClinica::formato($pago->created_at, 'd/m/Y H:i'),
                'medio' => $this->medioDe($pago),
                'canal' => $this->canalDe($pago),
            ];
        })->all();
    }

    public function hayPagos(): bool
    {
        return $this->pagos->isNotEmpty();
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['fecha' => $this->cita->fecha?->format('Y-m-d')]);
    }

    public function urlManual(): string
    {
        return route('clinica.agenda.cobro.manual', $this->cita->id);
    }

    public function urlPasarela(): string
    {
        return route('clinica.agenda.cobro.pasarela', $this->cita->id);
    }

    public function urlConfirmar(Pago $pago): string
    {
        return route('clinica.agenda.cobro.confirmar', [$this->cita->id, $pago->id]);
    }

    private function usd(float $monto): string
    {
        return Centavos::formatear(Centavos::deDecimal($monto), 'USD');
    }

    private function estadoDe(Pago $pago): string
    {
        return match ($pago->estado) {
            Pago::PENDIENTE => 'Pendiente',
            Pago::EN_PROCESO => 'Esperando el código',
            Pago::APROBADO => 'Cobrado',
            Pago::RECHAZADO => 'Rechazado',
            Pago::CANCELADO => 'Cancelado',
            Pago::EXPIRADO => 'Vencido',
            Pago::REEMBOLSADO => 'Reembolsado',
            default => ucfirst((string) $pago->estado),
        };
    }

    private function medioDe(Pago $pago): string
    {
        if ($pago->medio_manual && isset(CobrarEnElMostrador::MEDIOS[$pago->medio_manual])) {
            return CobrarEnElMostrador::MEDIOS[$pago->medio_manual];
        }

        if ($pago->metodo_pago_id) {
            return (string) ($pago->metodoPago?->nombre ?: 'Pasarela');
        }

        return '—';
    }

    private function canalDe(Pago $pago): string
    {
        return match ($pago->canal) {
            Pago::CANAL_MOSTRADOR => 'Mostrador',
            Pago::CANAL_LINK => 'Link de pago',
            Pago::CANAL_RESERVA_ONLINE => 'Reserva en línea',
            Pago::CANAL_PLAN => 'Plan',
            default => ucfirst((string) $pago->canal),
        };
    }
}
