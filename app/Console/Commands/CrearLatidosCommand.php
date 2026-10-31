<?php

namespace App\Console\Commands;

use App\Latidos\Latidos;
use App\Models\Latido;
use App\Models\Medico;
use Illuminate\Console\Command;

/**
 * **Crea la fila de Latidos de cada médico** (`php artisan latidos:crear`).
 *
 * No es obligatoria —`Latidos::para()` la crea sola la primera vez que se necesita—, pero deja el panel de
 * administración con todos los consultorios visibles desde el día uno. Es idempotente: no toca las filas
 * que ya existen y no pisa la preferencia de renovación automática.
 *
 * Los centros médicos (`medicos.es_centro = 1`) no tienen Latidos propios: no son doctores y no pagan plan.
 */
class CrearLatidosCommand extends Command
{
    protected $signature = 'latidos:crear {--simular : Muestra qué haría sin escribir nada}';

    protected $description = 'Crea la fila de Latidos de los consultorios que todavía no la tienen.';

    public function handle(Latidos $latidos): int
    {
        $simular = (bool) $this->option('simular');
        $creados = 0;
        $existentes = 0;

        Medico::query()
            ->whereNotNull('reg_medico')
            ->where('reg_medico', '!=', '')
            ->where('es_centro', false)
            ->orderBy('id')
            ->chunkById(200, function ($medicos) use (&$creados, &$existentes, $simular, $latidos) {
                foreach ($medicos as $medico) {
                    $regMedico = trim((string) $medico->reg_medico);
                    if ($regMedico === '') {
                        continue;
                    }

                    if (Latido::where('reg_medico', $regMedico)->exists()) {
                        $existentes++;

                        continue;
                    }

                    if (! $simular) {
                        $latidos->para($regMedico);
                    }
                    $creados++;
                }
            });

        $this->info(($simular ? 'Se crearían ' : 'Se crearon ') . $creados . ' fila(s) de Latidos; '
            . $existentes . ' ya existían.');

        return self::SUCCESS;
    }
}
