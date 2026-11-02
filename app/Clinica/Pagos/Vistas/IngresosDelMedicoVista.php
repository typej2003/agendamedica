<?php

namespace App\Clinica\Pagos\Vistas;

use App\Actions\Pagos\CobrarEnElMostrador;
use App\Models\Cola;
use App\Models\Pago;
use App\Pagos\Centavos;
use App\Support\FechaClinica;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as Coleccion;

/**
 * **La pantalla «Mis ingresos»** (O-53): los pagos de cita del médico, con sus estados y los totales del
 * período. No consulta la base: recibe lo que leyó `App\Pagos\IngresosDelMedico`.
 */
final class IngresosDelMedicoVista
{
    /**
     * @param  Collection<int,Pago>  $pagos
     * @param  Coleccion<int,Cola>  $citas  las citas de esos pagos, ya leídas en lote
     * @param  array{pagado_usd_e2:int, en_proceso_usd_e2:int, sin_cobrar_usd_e2:int, pagados:int, en_proceso:int, sin_cobrar:int}  $totales
     * @param  array{estado:string, desde:string, hasta:string}  $filtros
     */
    public function __construct(
        private readonly Collection $pagos,
        private readonly Coleccion $citas,
        private readonly array $totales,
        private readonly array $filtros,
    ) {
    }

    public function titulo(): string
    {
        return 'Mis ingresos';
    }

    public function subtitulo(): string
    {
        return 'Lo que entró por tus citas: cobrado, en proceso y lo que quedó sin cobrar.';
    }

    public function pagado(): string
    {
        return $this->usd((int) $this->totales['pagado_usd_e2']);
    }

    public function enProceso(): string
    {
        return $this->usd((int) $this->totales['en_proceso_usd_e2']);
    }

    public function sinCobrar(): string
    {
        return $this->usd((int) $this->totales['sin_cobrar_usd_e2']);
    }

    public function cuantosPagados(): int
    {
        return (int) $this->totales['pagados'];
    }

    public function cuantosEnProceso(): int
    {
        return (int) $this->totales['en_proceso'];
    }

    public function cuantosSinCobrar(): int
    {
        return (int) $this->totales['sin_cobrar'];
    }

    public function hayPagos(): bool
    {
        return $this->pagos->isNotEmpty();
    }

    public function estadoElegido(): string
    {
        return $this->filtros['estado'];
    }

    public function desde(): string
    {
        return $this->filtros['desde'];
    }

    public function hasta(): string
    {
        return $this->filtros['hasta'];
    }

    /**
     * Las opciones del filtro: los tres grupos y, debajo, los estados concretos que se pueden mirar uno por
     * uno. «En proceso» es el grupo; el estado «esperando el código» vive adentro y no se filtra aparte.
     *
     * @return array<string,string>
     */
    public function opcionesDeEstado(): array
    {
        return [
            '' => 'Todos',
            'pagados' => 'Cobrados',
            'en_proceso' => 'En proceso',
            'sin_cobrar' => 'Sin cobrar',
            'pendiente' => 'Pendiente',
            'aprobado' => 'Cobrado (uno por uno)',
            'rechazado' => 'Rechazado',
            'expirado' => 'Vencido',
            'cancelado' => 'Cancelado',
            'reembolsado' => 'Reembolsado',
        ];
    }
    /** @return array<int,array{id:int, fecha:string, concepto:string, paciente:string, monto:string, estado:string, tono:string, medio:string, canal:string}> */
    public function filas(): array
    {
        return $this->pagos->map(function (Pago $pago): array {
            $cita = $this->citas->get((int) $pago->pagable_id);
            $paciente = $cita?->paciente;

            return [
                'id' => (int) $pago->id,
                'fecha' => FechaClinica::formato($pago->pagado_el ?? $pago->created_at, 'd/m/Y H:i'),
                'concepto' => (string) $pago->concepto,
                'paciente' => trim((string) ($paciente?->nombres ?? '') . ' ' . (string) ($paciente?->apellidos ?? '')),
                'monto' => Centavos::formatear((int) $pago->monto_usd_e2, 'USD'),
                'estado' => $this->estadoDe($pago->estado),
                'tono' => match ($pago->estado) {
                    Pago::APROBADO => 'exito',
                    Pago::RECHAZADO, Pago::CANCELADO, Pago::EXPIRADO, Pago::REEMBOLSADO => 'error',
                    default => 'aviso',
                },
                'medio' => $this->medioDe($pago),
                'canal' => match ($pago->canal) {
                    Pago::CANAL_MOSTRADOR => 'Mostrador',
                    Pago::CANAL_LINK => 'Link de pago',
                    Pago::CANAL_RESERVA_ONLINE => 'Reserva en línea',
                    default => ucfirst((string) $pago->canal),
                },
            ];
        })->all();
    }

    public function urlFiltros(): string
    {
        return route('clinica.ingresos');
    }

    private function usd(int $e2): string
    {
        return Centavos::formatear($e2, 'USD');
    }

    private function estadoDe(?string $estado): string
    {
        return match ($estado) {
            Pago::PENDIENTE => 'Pendiente',
            Pago::EN_PROCESO => 'Esperando el código',
            Pago::APROBADO => 'Cobrado',
            Pago::RECHAZADO => 'Rechazado',
            Pago::CANCELADO => 'Cancelado',
            Pago::EXPIRADO => 'Vencido',
            Pago::REEMBOLSADO => 'Reembolsado',
            default => ucfirst((string) $estado),
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
}
