<?php

namespace App\Console\Commands;

use App\Models\Pago;
use App\Pagos\Cobros;
use Illuminate\Console\Command;

/**
 * **Conciliación de pagos** (`php artisan pagos:conciliar`): expira los pendientes vencidos y consulta a
 * SyPago los que quedaron en proceso para aplicar los aprobados.
 *
 * Corre cada minuto desde el scheduler. No reemplaza al webhook: lo cubre cuando el aviso no llega o la
 * consulta falló en el momento. `--limite` acota cuántos procesa por corrida.
 */
class PagosConciliarCommand extends Command
{
    protected $signature = 'pagos:conciliar {--limite=50 : Cuántos pagos procesar en esta corrida}';

    protected $description = 'Consulta a SyPago los pagos en proceso y aplica los aprobados; expira los vencidos.';

    public function handle(Cobros $cobros): int
    {
        $expirados = Pago::where('estado', Pago::PENDIENTE)
            ->whereNotNull('expira_el')
            ->where('expira_el', '<', now())
            ->update(['estado' => Pago::EXPIRADO]);

        if ($expirados > 0) {
            $this->info("Pagos vencidos: {$expirados}.");
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
