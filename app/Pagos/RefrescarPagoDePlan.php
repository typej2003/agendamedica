<?php

namespace App\Pagos;

use App\Models\ConfiguracionPago;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * **Renovar un pago de plan que venció** (o está por vencer) sin volver a crearlo.
 *
 * El checkout lo llama en segundo plano al acabarse el tiempo:
 *  - Si la tasa **no cambió**, el mismo pago se renueva (nueva vigencia) y el formulario sigue como estaba.
 *  - Si la tasa **cambió**, no se toca nada: se devuelve `cambio` con el monto nuevo para que el médico
 *    decida con el botón «Reintentar». Recién ahí se actualiza el pago con la tasa nueva.
 *
 * Acepta pagos `pendiente` y `expirado` (el comando de conciliación puede haberlo marcado vencido mientras
 * el médico tenía la pantalla abierta). Cualquier otro estado no se renueva.
 */
final class RefrescarPagoDePlan
{
    /**
     * @return array{estado:string, monto_e2:int, monto_texto:string, monto_usd_texto:string, segundos_restantes:int, mensaje:string}
     */
    public function ejecutar(Pago $pago, bool $aplicar): array
    {
        if (! $pago->esDePlan()) {
            throw new RuntimeException('Este pago no es de un plan.');
        }
        if (! in_array($pago->estado, [Pago::PENDIENTE, Pago::EXPIRADO], true)) {
            throw new RuntimeException('Este pago ya no se puede renovar.');
        }

        $minutos = max(1, (int) ConfiguracionPago::actual()->vigencia_tasa_minutos);
        $moneda = strtoupper((string) $pago->moneda);

        // Un pago en dólares no depende de la tasa: se renueva tal cual.
        if ($moneda === 'USD') {
            return $this->renovar($pago, $minutos);
        }
        if ($moneda !== 'VES') {
            throw new RuntimeException('La moneda del pago no está soportada.');
        }

        $tasa = TasaDeCambio::actual();
        if (! $tasa) {
            throw new RuntimeException('Todavía no hay una tasa VES/USD cargada.');
        }

        // La tasa se compara como texto decimal (el modelo la guarda con 6 decimales).
        if ((string) $pago->tasa === (string) $tasa->tasa) {
            return $this->renovar($pago, $minutos);
        }

        // La tasa cambia **sólo la parte de la pasarela**: los Latidos ya se descontaron en USD y no se
        // vuelven a convertir. El neto del plan sigue siendo el total de la venta, no lo que cobra SyPago.
        $montoE2 = $tasa->convertirUsdAVes((int) $pago->monto_pasarela_usd_e2);
        $netoVesE2 = $tasa->convertirUsdAVes((int) $pago->monto_usd_e2);

        if (! $aplicar) {
            return $this->resultado('cambio', $pago, $montoE2, $moneda, null,
                'La tasa cambió. El nuevo monto es ' . Centavos::formatear($montoE2, $moneda) . '.');
        }

        $pago->update([
            'estado' => Pago::PENDIENTE,
            'monto_e2' => $montoE2,
            'tasa' => $tasa->tasa,
            'neto_ves_e2' => $netoVesE2,
            'expira_el' => now()->addMinutes($minutos),
        ]);

        return $this->resultado('actualizado', $pago, $montoE2, $moneda, $pago->expira_el,
            'Pago actualizado con la tasa nueva.');
    }

    /** @return array{estado:string, monto_e2:int, monto_texto:string, monto_usd_texto:string, segundos_restantes:int, mensaje:string} */
    private function renovar(Pago $pago, int $minutos): array
    {
        $pago->update([
            'estado' => Pago::PENDIENTE,
            'expira_el' => now()->addMinutes($minutos),
        ]);

        return $this->resultado('renovado', $pago, (int) $pago->monto_e2, (string) $pago->moneda, $pago->expira_el,
            'El pago se renovó. Puedes continuar.');
    }

    /** @return array{estado:string, monto_e2:int, monto_texto:string, monto_usd_texto:string, segundos_restantes:int, mensaje:string} */
    private function resultado(
        string $estado,
        Pago $pago,
        int $montoE2,
        string $moneda,
        ?CarbonInterface $expira,
        string $mensaje
    ): array {
        return [
            'estado' => $estado,
            'monto_e2' => $montoE2,
            'monto_texto' => Centavos::formatear($montoE2, $moneda),
            'monto_usd_texto' => Centavos::formatear((int) $pago->monto_usd_e2, 'USD'),
            'segundos_restantes' => $expira ? max(0, (int) now()->diffInSeconds($expira, false)) : 0,
            'mensaje' => $mensaje,
        ];
    }
}
