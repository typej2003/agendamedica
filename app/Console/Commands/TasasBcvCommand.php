<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionPago;
use App\Pagos\Tasas\TasaBcv;
use Illuminate\Console\Command;

/**
 * **Sincroniza la tasa VES/USD desde el BCV** (`php artisan tasas:bcv`).
 *
 * Corre cada media hora desde el scheduler y **sólo guarda cuando la tasa cambió**. Si la preferencia
 * `configuracion_pagos.tasa_automatica` está apagada, no toca nada: el administrador la fijó a mano (con
 * `--forzar` se puede guardar igual, para una carga puntual).
 *
 * Cada intento queda registrado en `configuracion_pagos` (fecha, `ok`/`error` y el mensaje), para que el
 * panel muestre la última sincronización. Si el BCV no responde, el comando falla (código 1) y la tasa
 * anterior sigue vigente.
 */
class TasasBcvCommand extends Command
{
    protected $signature = 'tasas:bcv
        {--forzar : Guarda aunque la tasa no haya cambiado (y aunque la sincronización automática esté apagada)}
        {--dry-run : Muestra la tasa leída sin guardarla}';

    protected $description = 'Sincroniza la tasa VES/USD desde la página del Banco Central de Venezuela.';

    public function handle(TasaBcv $bcv): int
    {
        $forzar = (bool) $this->option('forzar');

        if (! $forzar && ! ConfiguracionPago::actual()->sincronizaLaTasa()) {
            $this->info('La sincronización automática está desactivada. Usá --forzar para guardarla igual.');

            return self::SUCCESS;
        }

        // El dry-run no deja registro de sincronización: es una lectura, no un intento real.
        if ($this->option('dry-run')) {
            try {
                $tasa = $bcv->obtener();
            } catch (\Throwable $e) {
                $this->error('No se pudo leer la tasa del BCV: ' . $e->getMessage());

                return self::FAILURE;
            }

            $this->line('Tasa BCV: 1 USD = ' . $tasa . ' VES');

            return self::SUCCESS;
        }

        $resultado = $bcv->intentar(null, $forzar);

        if (! $resultado['ok']) {
            $this->error('No se pudo sincronizar la tasa del BCV: ' . $resultado['mensaje']);

            return self::FAILURE;
        }

        if (! $resultado['tasa']) {
            $this->info('Sin cambios: la tasa del BCV es la misma (' . $resultado['leida'] . ').');

            return self::SUCCESS;
        }

        $this->info('Tasa BCV actualizada: 1 USD = ' . $resultado['tasa']->aTexto() . ' VES.');

        return self::SUCCESS;
    }
}
