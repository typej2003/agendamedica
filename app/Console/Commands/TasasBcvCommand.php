<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionPago;
use App\Pagos\Tasas\TasaBcv;
use Illuminate\Console\Command;
use Throwable;

/**
 * **Sincroniza la tasa VES/USD desde el BCV** (`php artisan tasas:bcv`).
 *
 * Corre cada media hora desde el scheduler y **sólo guarda cuando la tasa cambió**. Si el origen está en
 * `manual`, no toca nada: el administrador fijó un valor a propósito (con `--forzar` se puede guardar
 * igual, para una carga puntual).
 *
 * Si el BCV no responde, el comando falla (código 1) y deja un error en el log; la tasa anterior sigue
 * vigente, así que un cobro no se queda sin tasa por un problema del sitio del Banco Central.
 */
class TasasBcvCommand extends Command
{
    protected $signature = 'tasas:bcv
        {--forzar : Guarda aunque la tasa no haya cambiado (y aunque el origen sea manual)}
        {--dry-run : Muestra la tasa leída sin guardarla}';

    protected $description = 'Sincroniza la tasa VES/USD desde la página del Banco Central de Venezuela.';

    public function handle(TasaBcv $bcv): int
    {
        $forzar = (bool) $this->option('forzar');

        if (! $forzar && ! ConfiguracionPago::actual()->sincronizaLaTasa()) {
            $this->info('La tasa está en modo manual: no se sincroniza. Usá --forzar para guardarla igual.');

            return self::SUCCESS;
        }

        try {
            $tasa = $bcv->obtener();
        } catch (Throwable $e) {
            $this->error('No se pudo leer la tasa del BCV: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('Tasa BCV: 1 USD = ' . $tasa . ' VES');

            return self::SUCCESS;
        }

        try {
            $nueva = $bcv->guardar($tasa, null, $forzar);
        } catch (Throwable $e) {
            $this->error('No se pudo guardar la tasa del BCV: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! $nueva) {
            $this->info('Sin cambios: la tasa del BCV es la misma (' . $tasa . ').');

            return self::SUCCESS;
        }

        $this->info('Tasa BCV actualizada: 1 USD = ' . $nueva->aTexto() . ' VES.');

        return self::SUCCESS;
    }
}
