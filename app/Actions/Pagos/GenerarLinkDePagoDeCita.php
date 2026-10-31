<?php

namespace App\Actions\Pagos;

use App\Models\Cola;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Pagos\CatalogoDePasarelas;
use App\Pagos\Centavos;
use App\Pagos\Cobros;
use App\Pagos\ResultadoDeLinkDePago;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **El link de pago de una cita, desde la agenda** (punto 3 del cobro de citas): elige el método de pago
 * de la plataforma, reusa el link pendiente o crea uno nuevo y devuelve el pago que se comparte.
 *
 * Las reglas del alcance:
 *
 * - **El monto es el precio de la cita** (`cola.monto`, en USD): el cobro del 100 % por defecto. El
 *   porcentaje configurable es otra tarea, así que acá no se inventa ninguno.
 * - **Sin precio no hay link**: una cita con `monto` nulo o ≤ 0 no se cobra; la pantalla manda a ponerlo.
 * - **El método es el de la plataforma para cobros**, con la misma consulta que usa el checkout del plan
 *   (`MetodoPago::deLaPlataforma()->activos()->paraUso(...)`, SyPago y configurado). Si hay varios gana el
 *   primero por `orden`/`id`: elegir método es otra tarea.
 * - **Un solo link vivo por cita**: si ya hay un pendiente del mismo monto (y el link no venció) se reusa
 *   su token; si el precio cambió, los pendientes viejos quedan `cancelado` antes de crear el nuevo.
 * - **Una cita ya pagada no genera nada**: lo dice `Cola::estadoPago()` o un pago aprobado de la cita.
 *
 * La creación y la cancelación van en una transacción con la cita bloqueada: dos pestañas abriendo el
 * link a la vez no dejan dos pagos pendientes.
 */
final class GenerarLinkDePagoDeCita
{
    public function __construct(private readonly Cobros $cobros)
    {
    }

    /**
     * El método de pago de la plataforma para cobrar citas: el primero por `orden`/`id` que sea SyPago y
     * esté configurado. `null` significa que todavía no hay con qué cobrar.
     */
    public function metodo(): ?MetodoPago
    {
        return MetodoPago::deLaPlataforma()
            ->activos()
            ->paraUso(MetodoPago::COBROS)
            ->orderBy('orden')
            ->orderBy('id')
            ->get()
            // El cobro de la cita es con SyPago; un método manual no puede debitar el link.
            ->filter(fn (MetodoPago $metodo): bool => $metodo->pasarela === CatalogoDePasarelas::SYPAGO
                && $metodo->estaConfigurado())
            ->first();
    }

    public function ejecutar(Cola $cita, ?int $usuarioId = null): ResultadoDeLinkDePago
    {
        // `cola.monto` está en dólares: el link cobra el precio de la cita, no un porcentaje.
        $montoUsdE2 = Centavos::deDecimal($cita->monto);
        if ($montoUsdE2 <= 0) {
            return ResultadoDeLinkDePago::sinPrecio();
        }

        $metodo = $this->metodo();
        if ($metodo === null) {
            return ResultadoDeLinkDePago::sinMetodo();
        }

        try {
            return DB::transaction(function () use ($cita, $metodo, $usuarioId): ResultadoDeLinkDePago {
                // Bloquear la cita serializa dos aperturas simultáneas de la pantalla: la segunda ve el
                // pendiente que creó la primera y lo reusa.
                $bloqueada = Cola::whereKey($cita->id)->lockForUpdate()->first();

                if ($bloqueada === null) {
                    return ResultadoDeLinkDePago::error('La cita ya no existe.');
                }

                // El precio se vuelve a leer bajo el bloqueo: si el mostrador lo cambió mientras se abría
                // la pantalla, se cobra el vigente y los links del monto viejo quedan cancelados.
                $montoUsdE2 = Centavos::deDecimal($bloqueada->monto);
                if ($montoUsdE2 <= 0) {
                    return ResultadoDeLinkDePago::sinPrecio();
                }

                if ($this->estaPagada($bloqueada)) {
                    return ResultadoDeLinkDePago::yaPagada();
                }

                $vigente = $this->pendienteDelMismoMonto($bloqueada, $montoUsdE2);

                // Se cancele o no el precio, el link que se va a usar queda vivo y los demás no.
                $this->cancelarPendientesDeOtroMonto($bloqueada, $montoUsdE2, $vigente?->id);
                if ($vigente !== null) {
                    return ResultadoDeLinkDePago::listo($vigente);
                }

                return ResultadoDeLinkDePago::listo(
                    $this->cobros->crearParaCita(
                        $bloqueada,
                        $metodo,
                        $montoUsdE2,
                        Pago::CANAL_LINK,
                        $usuarioId,
                    )
                );
            });
        } catch (RuntimeException $e) {
            // Lo que el motor rechaza (la tasa que falta, el máximo del monto) se muestra tal cual.
            return ResultadoDeLinkDePago::error($e->getMessage());
        }
    }

    /** La cita ya no debe nada: lo dicen los montos de la agenda o un pago aprobado de la pasarela. */
    private function estaPagada(Cola $cita): bool
    {
        if ($cita->estadoPago() === Cola::PAGO_PAGADA) {
            return true;
        }

        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->where('estado', Pago::APROBADO)
            ->exists();
    }

    /**
     * El link pendiente que todavía sirve: mismo monto y sin vencer. Se incluye `en_proceso` porque el
     * modelo lo considera pendiente (`Pago::estaPendiente()`) y su token sigue siendo el del cobro que el
     * paciente está haciendo.
     */
    private function pendienteDelMismoMonto(Cola $cita, int $montoUsdE2): ?Pago
    {
        return Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->where('canal', Pago::CANAL_LINK)
            ->whereIn('estado', [Pago::PENDIENTE, Pago::EN_PROCESO])
            ->where('monto_usd_e2', $montoUsdE2)
            ->where(function ($consulta) {
                $consulta->whereNull('expira_el')->orWhere('expira_el', '>', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Los pendientes de la cita que no son el link que se va a usar quedan cancelados: no dos links vivos.
     * Un `en_proceso` no se toca (su débito ya está en curso en SyPago); sólo los `pendiente`.
     */
    private function cancelarPendientesDeOtroMonto(Cola $cita, int $montoNuevoUsdE2, ?int $exceptoId = null): void
    {
        $viejos = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where('pagable_id', $cita->id)
            ->where('canal', Pago::CANAL_LINK)
            ->where('estado', Pago::PENDIENTE)
            ->when($exceptoId !== null, fn ($consulta) => $consulta->where('id', '!=', $exceptoId))
            ->get();

        foreach ($viejos as $pago) {
            $montoAnterior = (int) $pago->monto_usd_e2;

            $pago->update(['estado' => Pago::CANCELADO]);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'cancelado_por_precio_nuevo', [
                'monto_usd_e2_anterior' => $montoAnterior,
                'monto_usd_e2_nuevo' => $montoNuevoUsdE2,
            ], Pago::PENDIENTE);
        }
    }
}
