<?php

namespace App\Console\Commands;

use App\Sync\Escritorio\RestauracionEscritorio;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

/**
 * Restauración manual nube → escritorio: escribe, en una carpeta, todos los datos del médico que
 * están en el API listos para volver a su base SQL Anywhere (ver `RestauracionEscritorio`).
 *
 * Es para el caso "se perdió la PC y hay que volver a llenar la base": la bajada automática
 * (`/api/sync/cambios/bajar`, Fase 2.B) todavía no existe.
 *
 *   php artisan sync:restaurar-escritorio --reg-medico=gineco-00001
 *   php artisan sync:restaurar-escritorio --reg-medico=gineco-00001 --salida=C:\temp\restauracion --zip
 *
 * Después, en la PC del consultorio: `restaurar-escritorio.bat` (bridge/restaurar-escritorio.vbs),
 * con GinecoReport cerrado y **antes** de correr `actualizar-consultorio.bat` (los triggers de la
 * Fase 2 anotarían como cambios todas las filas que se acaban de restaurar).
 */
class SyncRestaurarEscritorioCommand extends Command
{
    protected $signature = 'sync:restaurar-escritorio
        {--reg-medico= : reg_medico del médico a restaurar (obligatorio)}
        {--salida= : carpeta de salida (por defecto storage/app/restauracion/<reg_medico>)}
        {--tablas= : solo estas tablas, separadas por coma (pruebas o restauraciones parciales)}
        {--zip : empaquetar la carpeta en un .zip para llevarla a la PC}
        {--si : seguir aunque el reg_medico no esté registrado, sin preguntar}';

    protected $description = 'Exporta los datos de un médico del API para restaurar su base PowerBuilder';

    public function handle(): int
    {
        $regMedico = trim((string) $this->option('reg-medico'));
        if ($regMedico === '') {
            $this->error('Falta --reg-medico.');
            return self::FAILURE;
        }

        $servicio = app(RestauracionEscritorio::class);

        if (! $servicio->medicoDe($regMedico)) {
            $this->warn("Ojo: no hay ningún médico registrado con reg_medico='{$regMedico}'.");
            $confirmado = match (true) {
                (bool) $this->option('si')    => true,
                $this->input->isInteractive() => $this->confirm('¿Exportar igual?', false),
                default                        => false,
            };
            if (! $confirmado) {
                $this->error('Cancelado (usá --si para exportar igual).');
                return self::FAILURE;
            }
        }

        $soloTablas = null;
        $tablas = trim((string) ($this->option('tablas') ?? ''));
        if ($tablas !== '') {
            $soloTablas = array_values(array_filter(array_map('trim', explode(',', $tablas))));
        }

        $carpeta = trim((string) ($this->option('salida') ?? '')) ?: null;

        $this->line("Restaurando <info>{$regMedico}</info> en " . ($carpeta ?: $servicio->carpetaPorDefecto($regMedico)));

        try {
            $resultado = $servicio->exportar($regMedico, $carpeta, $soloTablas, function (string $tabla, int $filas) {
                $this->line('  ' . str_pad($tabla, 34) . ($filas === 0 ? 'sin filas' : number_format($filas)));
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Listo: {$resultado['total_filas']} filas en " . count($resultado['archivos']) . ' tablas.');
        $this->line('  Carpeta: ' . $resultado['carpeta']);
        $this->line('  Manifiesto: ' . $resultado['carpeta'] . DIRECTORY_SEPARATOR . 'manifiesto.txt');

        $this->compararConLaCarga($resultado);
        $this->avisarLoQueNoVuelve($resultado);

        if ($this->option('zip')) {
            $this->empaquetar($resultado['carpeta']);
        }

        $this->newLine();
        $this->comment('En la PC del consultorio, con GinecoReport cerrado y ANTES de actualizar-consultorio.bat:');
        $this->line('    restaurar-escritorio.bat /carpeta "<carpeta con los datos>" /simular');
        $this->line('    restaurar-escritorio.bat /carpeta "<carpeta con los datos>"');

        return self::SUCCESS;
    }

    /** Contraste con lo que el API dice haber recibido en la carga inicial: si no coincide, avisar. */
    private function compararConLaCarga(array $resultado): void
    {
        if ($resultado['carga'] === []) {
            $this->newLine();
            $this->comment('No hay una carga inicial registrada para este médico: no se puede contrastar.');
            return;
        }

        $diferencias = [];
        foreach ($resultado['carga'] as $tabla => $recibidas) {
            $exportadas = $resultado['filas'][$tabla] ?? null;
            if ($exportadas === null) {
                if ($recibidas > 0) {
                    $diferencias[] = "{$tabla}: la carga recibió {$recibidas} y no se exportó (¿tabla fuera del API?)";
                }
                continue;
            }
            if ($exportadas !== $recibidas) {
                $diferencias[] = "{$tabla}: carga {$recibidas} / exportadas {$exportadas}";
            }
        }

        if ($diferencias === []) {
            $this->newLine();
            $this->info('Los conteos coinciden con los de la carga inicial.');
            return;
        }

        $this->newLine();
        $this->warn('Conteos distintos a los de la carga inicial (las bajas del app y las filas nuevas explican diferencias):');
        foreach ($diferencias as $linea) {
            $this->line('  - ' . $linea);
        }
    }

    /** Lo que la nube no tiene y hay que rehacer a mano en la PC. */
    private function avisarLoQueNoVuelve(array $resultado): void
    {
        if ($resultado['omitidas'] !== []) {
            $this->newLine();
            $this->comment('Columnas que no vuelven (no viajan en el sync, hay que rehacerlas en la PC):');
            foreach ($resultado['omitidas'] as $tabla => $columnas) {
                $this->line('  - ' . $tabla . ': ' . implode(', ', $columnas));
            }
        }

        if ($resultado['notas'] !== []) {
            $this->newLine();
            $this->comment('Avisos:');
            foreach ($resultado['notas'] as $nota) {
                $this->line('  - ' . str_replace("\t", ' ', $nota));
            }
        }

        $this->newLine();
        $this->comment('No están en la nube y hay que rehacerlos en la PC: usuarios del escritorio (operadores),');
        $this->comment('clave del sistema y credenciales de SMS, logo del membrete, recipe.ini y las imágenes clínicas.');
    }

    private function empaquetar(string $carpeta): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->warn('No está la extensión zip de PHP: no se empaquetó.');
            return;
        }

        $ruta = $carpeta . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->warn("No se pudo crear {$ruta}.");
            return;
        }

        $base = rtrim($carpeta, '/\\') . DIRECTORY_SEPARATOR;
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterador as $archivo) {
            $zip->addFile($archivo->getPathname(), substr($archivo->getPathname(), strlen($base)));
        }
        $zip->close();

        $this->line('  Paquete: ' . $ruta);
    }
}
