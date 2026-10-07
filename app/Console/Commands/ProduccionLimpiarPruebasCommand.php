<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja la base lista para la primera carga real: borra las cuentas y los datos de PRUEBA que sembraron
 * `RoleAndUserSeeder`, `UserSeeder`, `MedicalDataSeeder` y `FakeClinicalDataSeeder`, o que quedaron de las
 * pruebas de carga (`carga-prueba-NN@example.com`).
 *
 *   php artisan produccion:limpiar-pruebas                         (solo muestra qué borraría)
 *   php artisan produccion:limpiar-pruebas --confirmar             (borra, pidiendo confirmación)
 *   php artisan produccion:limpiar-pruebas --conservar=carlos@gmail.com --conservar=otra@cuenta.com
 *
 * Qué se considera de prueba: los correos de `CUENTAS_DE_PRUEBA` y los que coinciden con
 * `PATRONES_DE_PRUEBA`, menos los que se pasen en `--conservar`. De esas cuentas se parte para saber qué
 * `reg_medico` y qué pacientes borrar; nada se decide por "parece de prueba" fuera de eso.
 *
 * Reglas que hacen seguro correrlo en el servidor:
 *  - Sin `--confirmar` no escribe nada (solo cuenta).
 *  - Se niega a correr si después no quedaría ningún administrador real (Root/Administrador): crealo
 *    antes con `php artisan cuentas:admin`.
 *  - Un `reg_medico` que comparte con una cuenta que SE CONSERVA no se toca (el `reg_medico` es la
 *    instancia del escritorio y puede tener varios doctores).
 *  - Un paciente solo se borra si ningún médico que se conserva lo tiene. Los catálogos (`planes`, países,
 *    especialidades, vademécum global…) no se tocan.
 *  - Todo va en una transacción: si algo falla, no se borra nada.
 *
 * No borra archivos (logo, firma, sello) que esas cuentas hayan subido a `storage/`.
 */
class ProduccionLimpiarPruebasCommand extends Command
{
    /** Cuentas que siembran los seeders. */
    public const CUENTAS_DE_PRUEBA = [
        'root@admin.com',
        'admin@gmail.com',
        'carlos@gmail.com',
        'ana@gmail.com',
        'maria@gmail.com',
        'alejandro@gmail.com',
        'elena@gmail.com',
        'roberto@gmail.com',
        'sofia@gmail.com',
    ];

    /** Cuentas de las pruebas de carga inicial (patrón LIKE). */
    public const PATRONES_DE_PRUEBA = ['carga-prueba-%@example.com'];

    /**
     * `reg_medico` de prueba que pueden existir sin cuenta (p. ej. la credencial de sync que se emitió para
     * probar el instalador). Patrón LIKE; se buscan en las tablas de `TABLAS_DE_REGISTROS`.
     */
    public const PATRONES_REG_DE_PRUEBA = ['prueba-%', 'gineco-prueba-%'];

    private const TABLAS_DE_REGISTROS = ['sync_credentials', 'reg_medico_servicio', 'sync_cargas', 'medico_registros', 'evolucion'];

    /** Centros médicos que siembra `MedicalDataSeeder`. */
    public const CENTROS_DE_PRUEBA = [
        'Centro Médico San José',
        'Clínica Especializada Metropolitana',
        'Hospital Privado Santa María',
    ];

    /** Tablas con `reg_medico` que no se barren por esa columna: se manejan aparte. */
    private const NO_BARRER = ['users', 'medicos'];

    protected $signature = 'produccion:limpiar-pruebas
        {--confirmar : borrar de verdad (sin esto solo se muestra qué se borraría)}
        {--conservar=* : correo de una cuenta de prueba que NO se debe borrar (repetible)}';

    protected $description = 'Borra las cuentas y los datos de prueba para empezar la primera carga real (por defecto, solo simula)';

    public function handle(): int
    {
        $conservar = array_map(fn ($e) => mb_strtolower(trim($e)), (array) $this->option('conservar'));
        $confirmar = (bool) $this->option('confirmar');

        $usuarios = $this->usuariosDePrueba($conservar);
        $regsSueltos = $this->regsDePruebaSueltos();
        if ($usuarios->isEmpty() && $regsSueltos->isEmpty()) {
            $this->info('No hay cuentas de prueba: no hay nada que limpiar.');

            return self::SUCCESS;
        }
        $userIds = $usuarios->pluck('id')->all();

        $medicos = DB::table('medicos')
            ->where(function ($q) use ($userIds, $usuarios) {
                $q->whereIn('user_id', $userIds)->orWhereIn('email', $usuarios->pluck('email')->all());
            })->get(['id', 'reg_medico']);
        $medicoIds = $medicos->pluck('id')->all();

        // reg_medico que solo usan cuentas de prueba (uno compartido con una cuenta que se conserva no se toca).
        $regsPrueba = $medicos->pluck('reg_medico')
            ->merge($usuarios->pluck('reg_medico'))
            ->merge($regsSueltos)
            ->filter()->unique()->values();
        $regsQueQuedan = DB::table('medicos')->whereNotIn('id', $medicoIds)->whereNotNull('reg_medico')->pluck('reg_medico')
            ->merge(DB::table('users')->whereNotIn('id', $userIds)->whereNotNull('reg_medico')->pluck('reg_medico'))
            ->unique();
        $regsCompartidos = $regsPrueba->intersect($regsQueQuedan)->values();
        $regs = $regsPrueba->diff($regsCompartidos)->values()->all();

        $quedan = User::whereNotIn('id', $userIds)->get();
        $administradoresReales = $quedan->filter(fn (User $u) => $u->esAdministrador());

        $this->line('');
        $this->info('Cuentas de prueba a borrar (' . $usuarios->count() . '):');
        $this->table(['id', 'correo', 'reg_medico'], $usuarios->map(fn ($u) => [$u->id, $u->email, $u->reg_medico ?: '-'])->all());
        $this->line('reg_medico de prueba: ' . ($regs ? implode(', ', $regs) : '(ninguno)'));
        if ($regsCompartidos->isNotEmpty()) {
            $this->warn('reg_medico compartidos con cuentas que se conservan (NO se borran sus datos): ' . $regsCompartidos->implode(', '));
        }
        $this->line('Cuentas que quedan: ' . ($quedan->isEmpty() ? '(ninguna)' : $quedan->pluck('email')->implode(', ')));

        if ($administradoresReales->isEmpty()) {
            $this->error('Después de limpiar no quedaría ningún administrador. Crea el primero antes con '
                . '`php artisan cuentas:admin {correo}` (la cuenta debe existir y no ser de prueba) y vuelve a correr esto.');

            return self::FAILURE;
        }

        $pacienteIds = $this->pacientesSoloDePrueba($medicoIds);

        if (! $confirmar) {
            $this->line('');
            $this->warn('SIMULACIÓN: no se borró nada. Esto es lo que se borraría:');
            $this->resumir($this->limpiar(false, $userIds, $medicoIds, $regs, $pacienteIds));
            $this->line('Para borrar de verdad: php artisan produccion:limpiar-pruebas --confirmar');

            return self::SUCCESS;
        }

        if (! $this->confirm('Esto borra esas cuentas y sus datos de forma definitiva. ¿Continuar?')) {
            $this->warn('Cancelado, no se borró nada.');

            return self::FAILURE;
        }

        $resultado = DB::transaction(fn () => $this->limpiar(true, $userIds, $medicoIds, $regs, $pacienteIds));
        $this->resumir($resultado);
        $this->info('Listo. La base queda sin cuentas ni datos de prueba.');

        return self::SUCCESS;
    }

    /** `reg_medico` de prueba que están en las tablas de registros aunque no haya cuenta. */
    private function regsDePruebaSueltos()
    {
        return collect(self::TABLAS_DE_REGISTROS)
            ->filter(fn ($t) => Schema::hasTable($t) && Schema::hasColumn($t, 'reg_medico'))
            ->flatMap(fn ($t) => DB::table($t)->where(function ($q) {
                foreach (self::PATRONES_REG_DE_PRUEBA as $patron) {
                    $q->orWhere('reg_medico', 'like', $patron);
                }
            })->pluck('reg_medico'))
            ->unique()->values();
    }

    /** Cuentas de prueba (por correo exacto o patrón) menos las que se piden conservar. */
    private function usuariosDePrueba(array $conservar)
    {
        return User::query()
            ->where(function ($q) {
                $q->whereIn('email', self::CUENTAS_DE_PRUEBA);
                foreach (self::PATRONES_DE_PRUEBA as $patron) {
                    $q->orWhere('email', 'like', $patron);
                }
            })
            ->when($conservar, fn ($q) => $q->whereNotIn('email', $conservar))
            ->get(['id', 'email', 'reg_medico']);
    }

    /**
     * Pacientes vinculados a médicos de prueba y a NINGÚN médico que se conserva. Se calcula antes de
     * borrar nada, porque después ya no queda el vínculo (`medico_pacientes`) del que se deduce.
     *
     * @return int[]
     */
    private function pacientesSoloDePrueba(array $medicoIds): array
    {
        if (! $medicoIds) {
            return [];
        }

        return DB::table('medico_pacientes')->whereIn('medico_id', $medicoIds)->distinct()->pluck('paciente_id')
            ->diff(DB::table('medico_pacientes')->whereNotIn('medico_id', $medicoIds)->distinct()->pluck('paciente_id'))
            ->values()->all();
    }

    /**
     * Recorre los pasos y devuelve `[descripción => filas]`. Con `$borrar = false` solo cuenta.
     * El orden respeta las dependencias (hijos antes que padres) sin apoyarse en los ON DELETE CASCADE.
     */
    private function limpiar(bool $borrar, array $userIds, array $medicoIds, array $regs, array $pacienteIds): array
    {
        $hecho = [];
        $paso = function (string $tabla, $consulta) use ($borrar, &$hecho) {
            $n = $borrar ? $consulta->delete() : $consulta->count();
            if ($n > 0) {
                $hecho[$tabla] = ($hecho[$tabla] ?? 0) + $n;
            }
        };
        $existe = fn (string $tabla, string $columna) => Schema::hasTable($tabla) && Schema::hasColumn($tabla, $columna);

        $officeIds = $existe('offices', 'medico_id')
            ? DB::table('offices')->where(function ($q) use ($medicoIds, $regs) {
                $q->whereIn('medico_id', $medicoIds ?: [0]);
                if ($regs) {
                    $q->orWhereIn('reg_medico', $regs);
                }
            })->pluck('id')->all()
            : [];

        // Centros de prueba que ningún médico que se conserva usa.
        $centroIds = DB::table('medical_centers')->whereIn('name', self::CENTROS_DE_PRUEBA)->pluck('id')
            ->reject(fn ($id) => DB::table('medico_medical_center')->where('medical_center_id', $id)->whereNotIn('medico_id', $medicoIds ?: [0])->exists())
            ->values()->all();
        if ($centroIds) {
            $officeIds = array_values(array_unique(array_merge(
                $officeIds,
                DB::table('offices')->whereIn('medical_center_id', $centroIds)->pluck('id')->all(),
            )));
        }

        // 1. Lo que cuelga de una sede, por su id. Lista cerrada a propósito: `medicos.office_id` también
        //    existe y borrar médicos por ahí se llevaría a uno que se conserva.
        foreach (['office_schedules', 'motivo_cita'] as $tabla) {
            if ($officeIds && $existe($tabla, 'office_id')) {
                $paso($tabla, DB::table($tabla)->whereIn('office_id', $officeIds));
            }
        }

        // 2. Todo lo que lleva `reg_medico` (la columna de tenant de casi todo el legado).
        if ($regs) {
            // Las cargas iniciales tienen hijas por `sync_carga_id`.
            if (Schema::hasTable('sync_carga_tablas') && Schema::hasTable('sync_cargas')) {
                $cargas = DB::table('sync_cargas')->whereIn('reg_medico', $regs)->pluck('id')->all();
                if ($cargas) {
                    $paso('sync_carga_tablas', DB::table('sync_carga_tablas')->whereIn('sync_carga_id', $cargas));
                }
            }
            foreach ($this->tablasConColumna('reg_medico') as $tabla) {
                if (in_array($tabla, self::NO_BARRER, true)) {
                    continue;
                }
                $paso($tabla, DB::table($tabla)->whereIn('reg_medico', $regs));
            }
        }

        // 3. Pacientes que solo eran de médicos de prueba (en lotes: el límite de variables de SQLite).
        foreach (array_chunk($pacienteIds, 500) as $lote) {
            $paso('pacientes', DB::table('pacientes')->whereIn('id', $lote));
        }

        // 4. Vínculos del médico y sus sedes.
        if ($medicoIds) {
            foreach (['medico_pacientes', 'medico_medical_center', 'medico_specialty'] as $tabla) {
                if ($existe($tabla, 'medico_id')) {
                    $paso($tabla, DB::table($tabla)->whereIn('medico_id', $medicoIds));
                }
            }
        }
        if ($officeIds) {
            $paso('offices', DB::table('offices')->whereIn('id', $officeIds));
        }
        if ($centroIds) {
            $paso('medical_centers', DB::table('medical_centers')->whereIn('id', $centroIds));
        }

        // 5. Cuentas: roles, tokens, médico y usuario.
        $tipoUsuario = User::class;
        foreach (['model_has_roles', 'model_has_permissions'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                $paso($tabla, DB::table($tabla)->where('model_type', $tipoUsuario)->whereIn('model_id', $userIds));
            }
        }
        if (Schema::hasTable('personal_access_tokens')) {
            $paso('personal_access_tokens', DB::table('personal_access_tokens')->where('tokenable_type', $tipoUsuario)->whereIn('tokenable_id', $userIds));
        }
        if ($medicoIds) {
            $paso('medicos', DB::table('medicos')->whereIn('id', $medicoIds));
        }
        $paso('users', DB::table('users')->whereIn('id', $userIds));

        // Un médico que se conserva no puede quedar apuntando a una sede borrada.
        if ($borrar && $officeIds && $existe('medicos', 'office_id')) {
            DB::table('medicos')->whereIn('office_id', $officeIds)->update(['office_id' => null]);
        }

        return $hecho;
    }

    /** @return string[] tablas que tienen la columna */
    private function tablasConColumna(string $columna): array
    {
        $driver = DB::connection()->getDriverName();
        $nombres = $driver === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->pluck('name')
            : collect(DB::select('select table_name as name from information_schema.tables where table_schema = database()'))->pluck('name');

        return $nombres->filter(fn ($t) => ! str_starts_with($t, 'sqlite_') && Schema::hasColumn($t, $columna))->values()->all();
    }

    private function resumir(array $hecho): void
    {
        if (! $hecho) {
            $this->line('(sin filas)');

            return;
        }
        ksort($hecho);
        $this->table(['tabla', 'filas'], collect($hecho)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
    }
}
