<?php

namespace App\Console\Commands;

use App\Clinica\FechaDelConsultorio;
use App\Latidos\RenovacionAutomatica;
use App\Latidos\RenovarPlanConLatidos;
use App\Models\Latido;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * **Renueva los planes con Latidos** (`php artisan latidos:renovar-planes`, fase 3).
 *
 * Corre todos los días a las 06:10 de Caracas. **No renueva antes del vencimiento**: cuando
 * `RenovacionAutomatica` dice que los Latidos cubren el 100 %, espera a que `vence_el` sea hoy (o a que el
 * servicio esté en gracia si una corrida falló) y ahí descuenta, extiende el plan y manda el comprobante.
 *
 * Si los Latidos no alcanzan, no toca nada: el médico sigue viendo el aviso normal y puede pagar la
 * diferencia desde `/clinica/plan`.
 */
class RenovarPlanConLatidosCommand extends Command
{
    protected $signature = 'latidos:renovar-planes {--simular : Muestra qué haría sin escribir nada}';

    protected $description = 'Renueva con Latidos los planes que vencen hoy y están cubiertos al 100 %.';

    public function handle(RenovacionAutomatica $renovacion, RenovarPlanConLatidos $accion): int
    {
        $simular = (bool) $this->option('simular');
        $hoy = FechaDelConsultorio::hoyComoFecha();
        $renovados = 0;
        $omitidos = 0;
        $errores = 0;

        Latido::query()
            ->where('renovacion_automatica', true)
            ->orderBy('id')
            ->chunkById(100, function ($filas) use (&$renovados, &$omitidos, &$errores, $simular, $hoy, $renovacion, $accion) {
                foreach ($filas as $fila) {
                    $estado = $renovacion->estado($fila->reg_medico);

                    if (! ($estado['cubierta'] ?? false) || empty($estado['vence_el'])) {
                        $omitidos++;

                        continue;
                    }

                    $vence = Carbon::parse((string) $estado['vence_el'])->startOfDay();
                    if ($vence->gt($hoy)) {
                        $omitidos++;

                        continue;
                    }

                    if ($simular) {
                        $this->line($fila->reg_medico . ': se renovaría «' . $estado['plan'] . '» con '
                            . $estado['latidos_necesarios'] . ' Latidos (vence ' . $estado['vence_el'] . ').');
                        $renovados++;

                        continue;
                    }

                    try {
                        $pago = $accion->ejecutar($fila->reg_medico, $estado, $hoy);
                        if ($pago) {
                            $renovados++;
                            $this->line($fila->reg_medico . ': renovado con ' . $pago->latidosTexto() . ' (pago #' . $pago->id . ').');
                        } else {
                            $omitidos++;
                        }
                    } catch (Throwable $e) {
                        $errores++;
                        $this->error($fila->reg_medico . ': ' . $e->getMessage());
                    }
                }
            });

        $this->info('Renovados: ' . $renovados . '; omitidos: ' . $omitidos . '; errores: ' . $errores . '.');

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
