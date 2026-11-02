<?php

namespace App\Console\Commands;

use App\Pagos\Liquidaciones\GenerarLiquidaciones;
use Illuminate\Console\Command;

/**
 * **Genera las liquidaciones T+1** de lo que la plataforma recaudó para cada médico: agrupa los pagos de
 * citas ya disponibles y arma una liquidación por médico. `--medico` acota a un consultorio.
 *
 * Las liquidaciones nacen confirmadas; se marcan pagadas con `liquidaciones:pagar` cuando se transfiere.
 */
class LiquidacionesGenerarCommand extends Command
{
    protected $signature = 'liquidaciones:generar {--medico= : Sólo este reg_medico}';

    protected $description = 'Agrupa los pagos de citas recaudados por la plataforma y genera las liquidaciones T+1.';

    public function handle(GenerarLiquidaciones $generador): int
    {
        $medico = $this->option('medico');
        $liquidaciones = $generador->ejecutar($medico !== null ? (string) $medico : null);

        if ($liquidaciones->isEmpty()) {
            $this->info('No hay pagos disponibles para liquidar.');

            return self::SUCCESS;
        }

        foreach ($liquidaciones as $liquidacion) {
            $this->line(sprintf(
                '#%d %s · %s a %s · neto %s',
                $liquidacion->id,
                $liquidacion->reg_medico,
                $liquidacion->periodo_desde?->format('d/m/Y'),
                $liquidacion->periodo_hasta?->format('d/m/Y'),
                $liquidacion->netoUsdTexto(),
            ));
        }

        $this->info('Liquidaciones generadas: ' . $liquidaciones->count() . '.');

        return self::SUCCESS;
    }
}
