<?php

namespace App\Console\Commands;

use App\Actions\Catalogos\SembrarCatalogosIniciales;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use Illuminate\Console\Command;

/**
 * **El catálogo inicial de cada especialidad, desde el escritorio** (WEB-12.9): carga en las filas iniciales lo que traen los archivos de
 * `database/catalogos-iniciales/<especialidad>/` y marca a qué especialidades aplica cada fila. La regla vive en `SembrarCatalogosIniciales`.
 *
 * Es idempotente (sólo agrega lo que falta), pero **se corre a mano, no en cada despliegue**: una fila que el administrador quitó vuelve a aparecer si se siembra
 * otra vez. `--simular` informa qué haría sin escribir nada. `--reemplazar=pediatria` deja el catálogo inicial de esa especialidad igual a su carpeta y `--solo=motivos-cita` siembra sólo ese catálogo (ver la Action). La carpeta `general` trae lo que recibe cualquier especialidad. Necesita las especialidades sembradas antes (`php artisan especialidades:sembrar`).
 */
class SembrarCatalogosInicialesCommand extends Command
{
    protected $signature = 'catalogos:sembrar-iniciales
        {--simular : informa qué agregaría, sin escribir nada}
        {--carpeta= : otra carpeta de archivos (por defecto database/catalogos-iniciales)}
        {--reemplazar=* : especialidad (slug) cuyo catálogo inicial pasa a ser exactamente el de su carpeta: se le quita lo que su archivo ya no trae}
        {--solo=* : catálogo a sembrar (motivos-cita, diagnosticos…), con los que referencia; sin la opción, todos}';

    protected $description = 'Carga el catálogo inicial de cada especialidad desde los archivos del escritorio (sólo agrega lo que falta)';

    public function handle(SembrarCatalogosIniciales $sembrar): int
    {
        $simular = (bool) $this->option('simular');
        $carpeta = $this->option('carpeta') ?: database_path('catalogos-iniciales');

        $informe = $sembrar->ejecutar($carpeta, $simular, array_values(array_filter((array) $this->option('reemplazar'))), array_values(array_filter((array) $this->option('solo'))));

        $this->line(($simular ? '<comment>Simulación</comment> — ' : '') . 'Especialidades: <info>' . implode(', ', array_keys($informe['especialidades'])) . '</info>');
        foreach ($informe['sin_especialidad'] as $slug) {
            $this->warn("La carpeta «{$slug}» no es una especialidad del catálogo: se saltó (¿falta especialidades:sembrar?).");
        }

        $this->table(['Catálogo', 'Filas nuevas', 'Generales', 'Marcas de especialidad', 'Líneas de tratamiento'], collect($informe['catalogos'])->map(
            fn ($c, $slug) => [CatalogosIniciales::de($slug)['titulo'], $c['nuevas'], $c['generales'], $c['marcas'], $c['lineas']]
        )->values()->all());

        foreach ($informe['retiradas'] as $especialidad => $retiradas) {
            $this->line('Reemplazo de <info>' . $especialidad . '</info>: lo que su archivo ya no trae.');
            $this->table(['Catálogo', 'Quitadas', 'Sin la marca', 'Conservadas'], collect($retiradas)->map(
                fn ($c, $slug) => [CatalogosIniciales::de($slug)['titulo'], $c['quitadas'], $c['desmarcadas'], $c['conservadas']]
            )->values()->all());
        }

        if ($informe['omitidas'] !== []) {
            $this->warn(count($informe['omitidas']) . ' filas omitidas:');
            foreach (array_slice($informe['omitidas'], 0, 20) as $texto) {
                $this->line('  · ' . $texto);
            }
        }

        $this->line($simular ? '--simular: no se escribió nada.' : 'Listo.');

        return self::SUCCESS;
    }
}
