<?php

namespace App\Actions\Pagos;

use App\Actions\Agenda\CobrarCita;
use App\Models\Cola;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Pagos\Centavos;
use App\Pagos\Cobros;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **Cobrar una cita en el mostrador** (O-53, 2026-11-02): el consultorio cobra con el paciente delante.
 *
 * Dos caminos y una sola regla de idempotencia:
 *
 * - **Pasarela**: se crea el pago con `Cobros::crearParaCita()` y el canal `mostrador`; el OTP y la
 *   confirmación los hace el mismo motor de siempre (`iniciar`/`confirmar`). Al aprobarse, el motor
 *   (`Cobros::aplicarCita`) escribe la cita con `CobrarCita`.
 * - **Manual**: el paciente pagó en efectivo, con punto de venta o por transferencia. No hay pasarela: el
 *   pago nace **aprobado**, con su `medio_manual`, y la cita se escribe acá con la misma `CobrarCita`.
 *
 * En los dos, **una cita ya pagada no se vuelve a cobrar**: lo dicen los montos de la agenda
 * (`Cola::estadoPago()`) o un pago aprobado de la cita. La pantalla lo avisa antes de abrir el formulario y
 * la Action lo vuelve a comprobar bajo el bloqueo de la fila, para que dos pestañas no cobren dos veces.
 */
final class CobrarEnElMostrador
{
    /** Los medios con los que el consultorio cobra sin pasarela. */
    public const MEDIOS = [
        'efectivo' => 'Efectivo',
        'punto_venta' => 'Punto de venta',
        'transferencia' => 'Transferencia',
        'otro' => 'Otro',
    ];

    public function __construct(private readonly Cobros $cobros)
    {
    }

    /** El método de la plataforma para cobrar citas, o `null` si todavía no hay con qué. */
    public function metodo(): ?MetodoPago
    {
        return app(GenerarLinkDePagoDeCita::class)->metodo();
    }

    /** ¿La cita ya está cobrada? Misma vara que el link de pago: los montos de la agenda o un pago aprobado. */
    public function estaPagada(Cola $cita): bool
    {
        if ($cita->estadoPago() === Cola::PAGO_PAGADA) {
            return true;
        }

        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->where('estado', Pago::APROBADO)
            ->exists();
    }

    /** Lo que falta cobrar, en centavos de USD (`cola.monto` − `cola.monto_pagado`). */
    public function pendienteUsdE2(Cola $cita): int
    {
        return (int) Centavos::deDecimal($cita->montoPendiente());
    }

    /** Los pagos de la cita, el último primero: es lo que muestra la pantalla del mostrador. */
    public function pagosDe(Cola $cita): Collection
    {
        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Crea la intención de pago por la **pasarela** para cobrarla en el mostrador.
     *
     * @throws RuntimeException si la cita ya está pagada, no tiene precio o no hay método configurado
     */
    public function crearParaLaPasarela(Cola $cita, ?int $usuarioId = null): Pago
    {
        return DB::transaction(function () use ($cita, $usuarioId): Pago {
            $bloqueada = Cola::whereKey($cita->id)->lockForUpdate()->first();

            if ($bloqueada === null) {
                throw new RuntimeException('La cita ya no existe.');
            }

            if ($this->estaPagada($bloqueada)) {
                throw new RuntimeException('Esta cita ya está pagada.');
            }

            $montoUsdE2 = $this->pendienteUsdE2($bloqueada);

            if ($montoUsdE2 <= 0) {
                throw new RuntimeException('La cita no tiene precio: ponlo antes de cobrar.');
            }

            $metodo = $this->metodo();

            if ($metodo === null) {
                throw new RuntimeException('No hay un método de pago configurado para cobrar con la pasarela.');
            }

            return $this->cobros->crearParaCita($bloqueada, $metodo, $montoUsdE2, Pago::CANAL_MOSTRADOR, $usuarioId);
        });
    }

    /**
     * **Registra un cobro manual** (efectivo, punto de venta, transferencia): el pago nace aprobado y la cita
     * se escribe con la misma acción del mostrador (`CobrarCita`), así el estado de la agenda y el pago no
     * pueden contradecirse.
     *
     * @throws RuntimeException si el medio no es uno de los conocidos, la cita ya está pagada o el monto no
     *                          es positivo
     */
    public function registrarManual(
        Cola $cita,
        float $recibido,
        ?string $medio = null,
        ?float $monto = null,
        ?int $usuarioId = null,
        ?string $nota = null,
    ): Pago {
        $medio = trim((string) $medio);

        if (! array_key_exists($medio, self::MEDIOS)) {
            throw new RuntimeException('Elige con qué se cobró.');
        }

        if ($recibido <= 0) {
            throw new RuntimeException('Lo recibido tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($cita, $recibido, $medio, $monto, $usuarioId, $nota): Pago {
            $bloqueada = Cola::whereKey($cita->id)->lockForUpdate()->first();

            if ($bloqueada === null) {
                throw new RuntimeException('La cita ya no existe.');
            }

            if ($this->estaPagada($bloqueada)) {
                throw new RuntimeException('Esta cita ya está pagada.');
            }

            // La cita primero: `recibir()` rechaza cobrar de más, y si rechaza no queda ni el pago ni el monto.
            app(CobrarCita::class)->recibir($bloqueada, $recibido, $monto, null, 'web');

            $montoUsdE2 = (int) Centavos::deDecimal($recibido);

            $pago = Pago::create([
                'reg_medico' => $bloqueada->reg_medico,
                'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
                'beneficiario_reg_medico' => $bloqueada->reg_medico,
                'recaudador' => Pago::RECAUDADOR_MEDICO,
                'canal' => Pago::CANAL_MOSTRADOR,
                'medio_manual' => $medio,
                'pagable_tipo' => Pago::PAGABLE_CITA,
                'pagable_id' => $bloqueada->id,
                'concepto' => 'Cita del ' . ($bloqueada->fecha?->format('d/m/Y') ?: 'día') . ' · #' . $bloqueada->id,
                'moneda' => 'USD',
                'monto_e2' => $montoUsdE2,
                'monto_usd_e2' => $montoUsdE2,
                // Sin pasarela no hay comisión de método ni de cita online: todo lo cobrado es del médico.
                'comision_metodo_usd_e2' => 0,
                'comision_cita_online_usd_e2' => 0,
                'comision_total_usd_e2' => 0,
                'neto_usd_e2' => $montoUsdE2,
                'comision_total_ves_e2' => 0,
                'neto_ves_e2' => 0,
                'estado' => Pago::APROBADO,
                'pagado_el' => now(),
                'estado_liquidacion' => Pago::LIQUIDACION_NO_APLICA,
                'datos_pagador' => array_filter(['nota' => trim((string) $nota)]),
                'creado_por' => $usuarioId,
            ]);

            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'aprobado_en_el_mostrador', [
                'medio' => $medio,
                'recibido' => $recibido,
            ], Pago::PENDIENTE);

            return $pago;
        });
    }
}
