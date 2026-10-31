<?php

namespace App\Console\Commands;

use App\Actions\Pagos\LiberarCupoDeReservaOnline;
use App\Latidos\Latidos;
use App\Models\Pago;
use App\Pagos\Cobros;
use Illuminate\Console\Command;

/**
 * **Conciliación de pagos** (`php artisan pagos:conciliar`): expira los pendientes vencidos y consulta a
 * SyPago los que quedaron en proceso para aplicar los aprobados.
 *
 * Al vencer un pago se hacen dos cosas más:
 *
 *  - se **devuelven los Latidos** que había retenido (`App\Latidos\Latidos::liberar`), para que el médico
 *    no los pierda por un pago que no se completó;
 *  - si es una **reserva online** y la cita sigue sin atender y sin pagos aprobados, se **libera el cupo**
 *    con `LiberarCupoDeReservaOnline` (el turno vuelve a estar disponible). Los pagos de la agenda
 *    (`link`) y de plan sólo se expiran.
 *
 * Corre cada minuto desde el scheduler. No reemplaza al webhook: lo cubre cuando el aviso no llega o la
 * consulta falló en el momento. `--limite` acota cuántos procesa por corrida.
 */
class PagosConciliarCommand extends Command
{
    protected $signature = 'pagos:conciliar {--limite=50 : Cuántos pagos procesar en esta corrida}';

    protected $description = 'Consulta a SyPago los pagos en proceso y aplica los aprobados; expira los vencidos, devuelve los Latidos y libera las reservas online.';

    public function handle(Cobros $cobros, LiberarCupoDeReservaOnline $liberar, Latidos $latidos): int
    {
        $expirados = 0;
        $liberados = 0;

        // Se procesan de a lotes para no cargar todos los vencidos de golpe. La condición de estado en el
        // UPDATE hace que dos corridas simultáneas no cuenten ni liberen dos veces el mismo pago.
        Pago::where('estado', Pago::PENDIENTE)
            ->whereNotNull('expira_el')
            ->where('expira_el', '<', now())
            ->orderBy('id')
            ->chunkById(200, function ($vencidos) use (&$expirados, &$liberados, $liberar, $latidos): void {
                foreach ($vencidos as $pago) {
                    $cambiado = Pago::whereKey($pago->id)
                        ->where('estado', Pago::PENDIENTE)
                        ->update(['estado' => Pago::EXPIRADO]);

                    if ($cambiado === 0) {
                        continue;
                    }

                    $expirados++;
                    $pago->refresh();

                    // El médico no pierde los Latidos que había retenido un pago que venció.
                    if ((int) $pago->latidos_usados > 0) {
                        $latidos->liberar($pago);
                    }

                    if ($pago->canal === Pago::CANAL_RESERVA_ONLINE && $liberar->ejecutar($pago)) {
                        $liberados++;
                    }
                }
            });

        if ($expirados > 0) {
            $this->info("Pagos vencidos: {$expirados}. Reservas liberadas: {$liberados}.");
        }

        $pagos = Pago::where('estado', Pago::EN_PROCESO)
            ->whereNotNull('otp_enviado_el')
            ->where('updated_at', '<=', now()->subSeconds(20))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();

        foreach ($pagos as $pago) {
            $resultado = $cobros->conciliar($pago);
            $this->line("#{$pago->id} ({$pago->referencia}): {$pago->estado} · {$resultado->mensaje}");
        }

        $this->info("Pagos consultados: {$pagos->count()}.");

        return self::SUCCESS;
    }
}
