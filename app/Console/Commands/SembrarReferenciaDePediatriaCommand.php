<?php

namespace App\Console\Commands;

use App\Actions\Catalogos\SembrarReferenciaDePediatria;
use Illuminate\Console\Command;

/**
 * **La referencia de pediatría** (curvas de crecimiento y catálogo inicial de vacunas) desde `database/seeders/data/pediatria/`. La regla vive en
 * `SembrarReferenciaDePediatria`. Como `catalogos:sembrar-iniciales`, **se corre a mano**: `--reemplazar-vacunas` cambia el catálogo inicial de vacunas entero por
 * el de los archivos (lo que el administrador le haya cambiado se pierde) y `--simular` informa sin escribir.
 */
class SembrarReferenciaDePediatriaCommand extends Command
{
    protected $signature = 'pediatria:sembrar-referencia
        {--reemplazar-vacunas : cambia el catálogo inicial de vacunas (tipos, vacunas y dosis) por el de los archivos}
        {--simular : informa qué haría, sin escribir nada}';

    protected $description = 'Carga las curvas de crecimiento y el catálogo inicial de vacunas de pediatría';

    public function handle(SembrarReferenciaDePediatria $sembrar): int
    {
        $simular = (bool) $this->option('simular');
        $informe = $sembrar->ejecutar((bool) $this->option('reemplazar-vacunas'), $simular);

        $this->table(['Tabla', 'Cargadas', 'Quitadas', 'IMC completado', 'Nota'], collect($informe)->map(fn ($c, $tabla) => [
            $tabla, $c['cargadas'], $c['quitadas'], $c['completadas'], $c['saltada'] ? 'ya había un catálogo inicial: --reemplazar-vacunas para cambiarlo' : '',
        ])->values()->all());

        $this->line($simular ? '--simular: no se escribió nada.' : 'Listo.');

        return self::SUCCESS;
    }
}
