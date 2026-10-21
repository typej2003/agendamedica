<?php

namespace App\Console\Commands;

use App\Services\CargaInicialService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arma los pacientes (paciente + relación con el médico + historia) desde la tabla de pacientes del
 * escritorio que **ya está en el API**, sin pedirle nada al escritorio ni al técnico.
 *
 * Para qué: cuando la fuente de pacientes de una especialidad no es `pacientes` —pediatría, que los tiene
 * en `paciente_chamo`— y la carga inicial ya se hizo, las filas están en su tabla del API pero **sin
 * historia**: sin historia no hay ficha, la web no lista al paciente y el app abre en blanco. Esto lo
 * repara sin rehacer la carga (que además ya no se puede: el médico tiene datos y `iniciar` la rechaza).
 *
 *   php artisan sync:pacientes-del-escritorio pedia_0001 --simular    (cuenta, sin escribir)
 *   php artisan sync:pacientes-del-escritorio pedia_0001
 *
 * Es idempotente: se puede correr las veces que haga falta. El detalle está en
 * `CargaInicialService::pacientesDesdeLaTablaDelEscritorio`.
 */
class SyncPacientesDelEscritorioCommand extends Command
{
    protected $signature = 'sync:pacientes-del-escritorio
        {reg_medico : el `reg_medico` del médico (el de su `sync.ini`)}
        {--tabla= : forzar la tabla de pacientes (por defecto: la que declare su especialidad, o la que tenga filas suyas)}
        {--simular : cuenta lo que haría y no escribe nada}';

    protected $description = 'Arma pacientes e historias desde la tabla de pacientes del escritorio que ya está en el API';

    public function handle(CargaInicialService $cargas): int
    {
        $regMedico = (string) $this->argument('reg_medico');
        $simular = (bool) $this->option('simular');
        $tabla = $this->option('tabla') ? (string) $this->option('tabla') : null;

        try {
            $resultado = $cargas->pacientesDesdeLaTablaDelEscritorio($regMedico, $simular, $tabla);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resultado['tablas'] === []) {
            $this->warn("El médico {$regMedico} no tiene ninguna tabla de pacientes que se pueda usar.");
            $this->line('Ni su especialidad declara una (su `pacientes` de siempre la procesa la carga inicial),');
            $this->line('ni hay una tabla de pacientes suya con filas en el API.');
            $this->newLine();
            $this->line('Si la carga subió los pacientes a una tabla propia (pediatría: `paciente_chamo`), revisa');
            $this->line('que el médico tenga su especialidad asignada en el panel (el pivote `medico_specialty`)');
            $this->line('o pasá la tabla a mano: --tabla=paciente_chamo');

            return self::SUCCESS;
        }

        $this->line($simular ? 'SIMULACIÓN (no se escribió nada)' : 'Escribiendo…');

        $filas = [];
        foreach ($resultado['tablas'] as $tabla => $conteo) {
            $filas[] = [$tabla, $conteo['filas'], $conteo['nuevas'], $conteo['existentes'], $conteo['omitidas']];
        }

        $this->table(
            ['tabla del escritorio', 'filas', 'pacientes nuevos', 'ya existían', 'omitidas'],
            $filas
        );

        $this->info(sprintf(
            '%s %d pacientes y actualizó %d (las dos cifras son pacientes con su historia).',
            $simular ? 'Crearía' : 'Creó',
            $resultado['creados'],
            $resultado['actualizados']
        ));

        if ($simular) {
            $this->line('Corrélo sin --simular para que quede hecho.');
        }

        return self::SUCCESS;
    }
}
