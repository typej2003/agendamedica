<?php

namespace App\Console\Commands;

use App\Latidos\Latidos;
use App\Models\Latido;
use Illuminate\Console\Command;

/**
 * **Revisa el libro de Latidos** (`php artisan latidos:verificar`).
 *
 * Compara la caché `latidos.disponibles` con la suma de `movimientos_de_latidos`, revisa las retenciones
 * huérfanas y los disponibles negativos. No corrige nada: si encuentra un descuadre, lo informa y termina
 * en error para que el despliegue o el cron avisen. Corregirlo es un ajuste manual de Root, con su motivo.
 */
class VerificarLatidosCommand extends Command
{
    protected $signature = 'latidos:verificar {--reg-medico= : Revisa sólo ese consultorio}';

    protected $description = 'Comprueba que la caché de Latidos coincida con el libro de movimientos.';

    public function handle(Latidos $latidos): int
    {
        $regMedico = trim((string) $this->option('reg-medico'));
        $consulta = Latido::query()->orderBy('id');
        if ($regMedico !== '') {
            $consulta->where('reg_medico', $regMedico);
        }

        $revisados = 0;
        $problemas = 0;

        $consulta->chunkById(200, function ($filas) use (&$revisados, &$problemas, $latidos) {
            foreach ($filas as $fila) {
                $revisados++;
                $resultado = $latidos->verificar($fila->reg_medico);
                if ($resultado['ok']) {
                    continue;
                }

                $problemas++;
                $this->error($fila->reg_medico . ': ' . implode(' ', $resultado['diferencias']));
            }
        });

        if ($problemas === 0) {
            $this->info('Latidos revisados: ' . $revisados . ', sin diferencias.');

            return self::SUCCESS;
        }

        $this->warn('Latidos revisados: ' . $revisados . ', con ' . $problemas . ' problema(s).');

        return self::FAILURE;
    }
}
