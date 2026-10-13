<?php

namespace App\Console\Commands;

use App\Sync\Escritorio\TablasDelSync;
use Illuminate\Console\Command;

/**
 * Escribe el `tablas-sync.txt` de un paquete del escritorio: la lista de tablas que su
 * `actualizar-consultorio` registra como triggers de `sync_cambios`.
 *
 * Por qué es un comando y no un archivo a mano: el archivo que se copiaba al paquete
 * (`bridge/tablas-sync.txt`) tenía **solo la lista histórica**, la de ginecología. Un paquete de otra
 * especialidad salía con esa lista y **sus tablas no se vigilaban**: los datos se quedaban en la PC sin
 * que nada avisara (el escritorio sólo registra los triggers de lo que le dicen). La lista sale de la
 * MISMA fuente que le ofrece las tablas por `POST /api/sync/cambios/estado`
 * (`App\Sync\Escritorio\TablasDelSync`), así que el archivo y lo que responde el API no pueden divergir.
 *
 * Uso:
 *   php artisan sync:tablas-escritorio --especialidad=pediatria --salida=A:\ruta\tablas-sync-pediatria.txt
 *   php artisan sync:tablas-escritorio                      # la lista histórica (ginecología y las que no tienen manifiesto)
 */
class SyncTablasEscritorioCommand extends Command
{
    protected $signature = 'sync:tablas-escritorio
                            {--especialidad= : slug del manifiesto (pediatria, ginecologia…); vacío = la lista histórica}
                            {--salida= : archivo a escribir; sin esto lo imprime en pantalla}';

    protected $description = 'Escribe la lista de tablas que el escritorio tiene que vigilar (por especialidad)';

    public function handle(TablasDelSync $tablas): int
    {
        $especialidad = trim((string) $this->option('especialidad'));
        $lista = $especialidad === ''
            ? $tablas->para(null)
            : $tablas->para($especialidad);

        if ($especialidad !== '') {
            if (! array_key_exists($especialidad, (array) config('sync_legado.tablas_por_especialidad', []))) {
                $this->warn("La especialidad '{$especialidad}' no agrega tablas (no tiene manifiesto con sync.tablas): sale la lista histórica.");
            }
            $extra = $tablas->deLaEspecialidad($especialidad);
            $this->line(sprintf(
                '%s: %d tablas (%d históricas + %d propias de la especialidad)',
                $especialidad,
                count($lista),
                count($tablas->historicas()),
                count($extra)
            ));
        } else {
            $this->line('lista histórica: ' . count($lista) . ' tablas');
        }

        $contenido = implode("\r\n", $lista) . "\r\n";

        $salida = $this->option('salida');
        if ($salida === null || trim($salida) === '') {
            $this->line($contenido);

            return self::SUCCESS;
        }

        $carpeta = dirname($salida);
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }
        file_put_contents($salida, $contenido);

        $this->info('escrito: ' . $salida);

        return self::SUCCESS;
    }
}
