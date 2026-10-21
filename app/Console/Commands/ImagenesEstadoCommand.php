<?php

namespace App\Console\Commands;

use App\Sync\ImagenesPc\ReferenciasDeImagenes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * **¿Terminó este consultorio de subir sus imágenes?** (WEB-T.1). Informa, por tabla y columna, cuántas referencias a
 * archivos de la PC hay, cuántas ya están **subidas**, cuántas se **omitieron** (por motivo) y cuántas siguen
 * **pendientes**, más el peso total subido. No escribe nada.
 *
 *   php artisan imagenes:estado --reg-medico=gineco-00001
 *   php artisan imagenes:estado --todos
 *
 * Termina con código 0 siempre que se pudo leer; el código 2 es de uso. «Terminó» = **pendientes en 0** (lo omitido es una
 * decisión registrada y se revisa en el informe CSV de la herramienta).
 */
class ImagenesEstadoCommand extends Command
{
    protected $signature = 'imagenes:estado
        {--reg-medico= : reg_medico del médico a informar}
        {--todos : informar todos los médicos que tengan referencias o registros}';

    protected $description = 'Informa el avance de la subida de imágenes de la PC del consultorio a la nube (WEB-T.1)';

    public function handle(ReferenciasDeImagenes $referencias): int
    {
        $medicos = $this->medicos();
        if ($medicos === null) {
            $this->error('Indicá --reg-medico=<medico> o --todos.');

            return 2;
        }

        $hubo = false;
        foreach ($medicos as $reg) {
            $filas = $referencias->resumen($reg);
            if ($filas === []) {
                if (! $this->option('todos')) {
                    $this->line("{$reg}: sin referencias a imágenes de la PC.");
                }
                continue;
            }

            $hubo = true;
            $this->line('');
            $this->info($reg);

            $tabla = [];
            $totales = ['referencias' => 0, 'subidas' => 0, 'omitidas_total' => 0, 'pendientes' => 0, 'peso' => 0];
            foreach ($filas as $f) {
                $omitidas = $f['omitidas_total'] === 0 ? '0' : $f['omitidas_total'] . ' (' . $this->motivos($f['omitidas']) . ')';
                $tabla[] = [
                    $f['tabla'] . '.' . $f['columna'], $f['referencias'], $f['subidas'], $omitidas, $f['pendientes'], $this->peso($f['peso']),
                ];
                foreach (array_keys($totales) as $k) {
                    $totales[$k] += $f[$k];
                }
            }
            $tabla[] = ['TOTAL', $totales['referencias'], $totales['subidas'], $totales['omitidas_total'], $totales['pendientes'], $this->peso($totales['peso'])];

            $this->table(['Tabla.columna', 'Referencias', 'Subidas', 'Omitidas', 'Pendientes', 'Peso subido'], $tabla);
            $this->line($totales['pendientes'] === 0
                ? 'Sin pendientes: la subida de este consultorio terminó.'
                : "Faltan {$totales['pendientes']} por subir: vuelve a correr subir-imagenes en la PC del consultorio.");
        }

        if (! $hubo && $this->option('todos')) {
            $this->line('Ningún médico tiene referencias a imágenes de la PC.');
        }

        return 0;
    }

    /** @return array<int,string>|null */
    private function medicos(): ?array
    {
        $uno = trim((string) $this->option('reg-medico'));
        if ($uno !== '') {
            return [$uno];
        }
        if (! $this->option('todos')) {
            return null;
        }

        return DB::table('medico_registros')->whereNotNull('reg_medico')->distinct()->orderBy('reg_medico')->pluck('reg_medico')
            ->merge(DB::table('imagenes_de_la_pc')->distinct()->pluck('reg_medico'))
            ->filter()->unique()->sort()->values()->all();
    }

    /** @param array<string,int> $omitidas */
    private function motivos(array $omitidas): string
    {
        return implode(', ', array_map(fn ($motivo, $n) => "{$motivo}: {$n}", array_keys($omitidas), $omitidas));
    }

    private function peso(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        return $bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }
}
