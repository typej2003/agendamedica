<?php

namespace App\Console\Commands;

use App\Models\Liquidacion;
use App\Pagos\Liquidaciones\GenerarLiquidaciones;
use Illuminate\Console\Command;
use Throwable;

/**
 * **Marca una liquidación como pagada**: el administrador ya transfirió al médico. La referencia de la
 * transferencia queda guardada para soporte.
 */
class LiquidacionesPagarCommand extends Command
{
    protected $signature = 'liquidaciones:pagar {liquidacion : El id de la liquidación} {--referencia= : La referencia de la transferencia}';

    protected $description = 'Marca una liquidación como pagada, con la referencia de la transferencia.';

    public function handle(GenerarLiquidaciones $generador): int
    {
        $liquidacion = Liquidacion::find((int) $this->argument('liquidacion'));

        if ($liquidacion === null) {
            $this->error('No existe esa liquidación.');

            return self::FAILURE;
        }

        try {
            $generador->pagar($liquidacion, $this->option('referencia'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Liquidación #{$liquidacion->id} marcada como pagada ({$liquidacion->netoUsdTexto()}).");

        return self::SUCCESS;
    }
}
