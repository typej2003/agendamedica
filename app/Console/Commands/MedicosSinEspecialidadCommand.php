<?php

namespace App\Console\Commands;

use App\Models\Medico;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * **Auditoría, de sólo lectura, de los médicos que no tienen especialidad asignada** (2026-10-30).
 *
 * Por qué existe: hasta hoy, un contexto **sin especialidad** se comportaba como ginecología
 * (`AlcanceDeLaEspecialidad::esGinecologia()` devolvía `true` con `null`), así que ese médico veía el estudio
 * ecográfico, el control de embarazo, el examen ginecológico y sus plantillas —contenido que su especialidad no
 * le da—. El fix invierte la regla: sin especialidad, el alcance es el **núcleo de medicina general**.
 *
 * El fix tiene un costo: un médico que de verdad es ginecólogo y no tiene la especialidad cargada **pierde** esas
 * pantallas hasta que se le asigne. Por eso este comando va **primero**: lista a quién hay que asignársela, con
 * el dato que hace falta para decidir (cuántas consultas tiene en ese registro), y no escribe nada.
 *
 * No adivina la especialidad —la regla del proyecto es que adivinar mal es peor que no adivinar—: sólo informa.
 * Los **centros** (`es_centro`) quedan fuera de la lista: un centro no atiende y no tiene especialidad propia;
 * sus médicos son los que se miran.
 *
 * Uso:
 *   php artisan medicos:sin-especialidad               tabla legible
 *   php artisan medicos:sin-especialidad --json        JSON, para guardar el resultado de la auditoría
 *   php artisan medicos:sin-especialidad --todos       además, el reparto de especialidades de los que sí tienen
 */
class MedicosSinEspecialidadCommand extends Command
{
    protected $signature = 'medicos:sin-especialidad
        {--json : salida JSON en vez de tabla}
        {--todos : incluye el reparto de los que sí tienen especialidad}';

    protected $description = 'Lista, sin escribir nada, los médicos sin especialidad asignada (auditoría previa al fix del alcance)';

    public function handle(): int
    {
        $sinEspecialidad = $this->sinEspecialidad();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'sin_especialidad' => $sinEspecialidad,
                'total'            => count($sinEspecialidad),
                'reparto'          => $this->option('todos') ? $this->reparto() : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($sinEspecialidad === []) {
            $this->info('No hay médicos sin especialidad: todos los que atienden tienen al menos una activa.');

            return $this->repartoSiSePidio();
        }

        $this->warn(sprintf(
            'Hay %d médico(s) sin especialidad activa. Con el fix del alcance ven el núcleo de medicina general, '
            . 'no ginecología: si alguno es ginecólogo, asígnale la especialidad antes de desplegar.',
            count($sinEspecialidad)
        ));
        $this->newLine();

        $this->table(
            ['id', 'reg_medico', 'médico', 'correo', 'activo', 'consultas'],
            array_map(fn (array $fila) => [
                $fila['id'],
                $fila['reg_medico'],
                $fila['nombre'],
                $fila['correo'] ?? '—',
                $fila['activo'] ? 'sí' : 'no',
                $fila['consultas'],
            ], $sinEspecialidad)
        );

        return $this->repartoSiSePidio();
    }

    private function repartoSiSePidio(): int
    {
        if (! $this->option('todos')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Reparto de especialidades (sólo lectura):');
        $this->table(
            ['especialidad', 'médicos'],
            array_map(fn (array $fila) => [$fila['especialidad'], $fila['medicos']], $this->reparto())
        );

        return self::SUCCESS;
    }

    /**
     * Los médicos que atienden y no tienen **ninguna especialidad activa** en el pivote `medico_specialty`.
     *
     * «Activa» es la misma vara que usa el contexto (`ContextoTrabajo::acceso()`: `specialties->where('activo',
     * true)`): un médico con la fila del pivote pero la especialidad desactivada tampoco ve sus módulos, así que
     * cuenta como que no la tiene.
     *
     * @return list<array{id:int, reg_medico:string|null, nombre:string, correo:string|null, activo:bool, consultas:int}>
     */
    private function sinEspecialidad(): array
    {
        $medicos = Medico::query()
            ->with(['specialties', 'user:id,email'])
            ->where('es_centro', false)
            ->orderBy('id')
            ->get();

        $salida = [];

        foreach ($medicos as $medico) {
            if ($medico->specialties->where('activo', true)->isNotEmpty()) {
                continue;
            }

            $salida[] = [
                'id'         => (int) $medico->id,
                'reg_medico' => $medico->reg_medico !== null ? (string) $medico->reg_medico : null,
                'nombre'     => trim(trim((string) $medico->name) . ' ' . trim((string) $medico->lastname)) ?: '—',
                'correo'     => $medico->user?->email ?? ($medico->email ?: null),
                'activo'     => (bool) $medico->is_active,
                'consultas'  => $this->consultas($medico->reg_medico),
            ];
        }

        return $salida;
    }

    /** Cuántas consultas hay en ese registro: es el peso real de lo que ve hoy. */
    private function consultas(?string $regMedico): int
    {
        if ($regMedico === null || $regMedico === '') {
            return 0;
        }

        return (int) DB::table('consultas')->where('reg_medico', $regMedico)->count();
    }

    /** @return list<array{especialidad:string, medicos:int}> */
    private function reparto(): array
    {
        return DB::table('medico_specialty as ms')
            ->join('specialties as s', 's.id', '=', 'ms.specialty_id')
            ->where('s.activo', true)
            ->groupBy('s.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([DB::raw('s.name as especialidad'), DB::raw('count(*) as medicos')])
            ->map(fn ($fila) => ['especialidad' => (string) $fila->especialidad, 'medicos' => (int) $fila->medicos])
            ->all();
    }
}
