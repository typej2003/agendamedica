<?php

namespace App\Console\Commands;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\MedicoRegistro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * **Las consultas cuyo número de historia pertenece a otro consultorio del mismo médico** (H-2, 2026-11-02).
 *
 * El número de historia es único **por registro** (`historias` tiene la clave `(reg_medico, numhistoria)`), pero el
 * legado lo repite entre consultorios: el 21 de un consultorio y el 21 de otro son historias distintas, con
 * pacientes distintos.
 *
 * Mientras la consulta resolvía el paciente con un atajo entre todos los registros del médico, una consulta así
 * mostraba al paciente de **otra** historia (se vio imprimiendo la hoja pre-operatoria). Después de la corrección
 * esas consultas se quedan sin paciente: el documento deja de imprimirse en vez de nombrar a otra persona. Este
 * comando las lista para saber cuántas son **antes** de desplegar, y para que el consultorio las corrija a mano.
 *
 * Es de **sólo lectura**: no toca ninguna fila.
 */
class HistoriasCruzadasCommand extends Command
{
    protected $signature = 'clinica:historias-cruzadas
                            {--limite=50 : cuántas filas mostrar}';

    protected $description = 'Lista las consultas cuyo número de historia es de otro consultorio del mismo médico (sólo lectura)';

    public function handle(): int
    {
        $limite = max(1, (int) $this->option('limite'));

        // Los registros de cada médico: es lo único que puede cruzar una consulta (nunca otro médico).
        $registrosDelMedico = [];

        foreach (MedicoRegistro::query()->orderBy('id')->get(['medico_id', 'reg_medico']) as $fila) {
            $registrosDelMedico[(int) $fila->medico_id][] = (string) $fila->reg_medico;
        }

        $medicoDelRegistro = [];

        foreach ($registrosDelMedico as $medicoId => $registros) {
            foreach ($registros as $registro) {
                $medicoDelRegistro[$registro] ??= (int) $medicoId;
            }
        }

        // Las historias registradas, por `reg_medico` y número. Se cargan una vez: son pocas y así la comparación es
        // la misma que hace `DuenoDeLaHistoria` (el número del legado puede traer ceros a la izquierda).
        $historias = [];

        foreach (Historia::query()->whereNotNull('paciente_id')->get(['reg_medico', 'numhistoria', 'paciente_id']) as $fila) {
            $numero = (string) (int) $fila->numhistoria;
            $historias[(string) $fila->reg_medico][$numero] = (int) $fila->paciente_id;
        }

        $cruzadas = [];

        foreach (Consulta::query()->orderBy('id')->cursor() as $consulta) {
            $registro = (string) $consulta->reg_medico;
            $numero = (string) (int) $consulta->numhistoria;

            if ($numero === '' || $numero === '0' || isset($historias[$registro][$numero])) {
                continue;
            }

            $medicoId = $medicoDelRegistro[$registro] ?? null;

            if ($medicoId === null) {
                continue;
            }

            foreach ($registrosDelMedico[$medicoId] ?? [] as $otro) {
                if ($otro === $registro || ! isset($historias[$otro][$numero])) {
                    continue;
                }

                $cruzadas[] = [
                    'consulta' => (int) $consulta->id,
                    'registro' => $registro,
                    'numero' => $numero,
                    'otro_registro' => $otro,
                    'paciente_ajeno' => $historias[$otro][$numero],
                ];

                break;
            }
        }

        if ($cruzadas === []) {
            $this->info('No hay consultas con el número de historia de otro consultorio.');

            return self::SUCCESS;
        }

        $this->warn(count($cruzadas) . ' consulta(s) con el número de historia de otro consultorio del mismo médico.');
        $this->line('Esas consultas ya no resuelven paciente: conviene corregir el número o el vínculo en el consultorio.');
        $this->newLine();

        $this->table(
            ['consulta', 'registro', 'n.º', 'otro registro', 'paciente ajeno'],
            array_slice($cruzadas, 0, $limite),
        );

        if (count($cruzadas) > $limite) {
            $this->line('(se muestran ' . $limite . ' de ' . count($cruzadas) . '; subí --limite para ver más)');
        }

        return self::SUCCESS;
    }
}
