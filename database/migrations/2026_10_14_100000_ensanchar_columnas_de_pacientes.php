<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pacientes` se ensancha para que entre el paciente **pediátrico**.
 *
 * La tabla `pacientes` del API es la del legado de ginecología, y sus largos son los de ESA base: `nombres`
 * son **25** caracteres. El chamo de pediatría, en cambio, tiene un solo campo `nombre` de **200** (la
 * persona entera: «MARIA DE LOS ANGELES MEJIA S…»), y el `email` del legado es 120 contra los 100 del API.
 * El primer backfill de pediatría se cortó con
 * `SQLSTATE[22001]: ... 1406 Data too long for column 'nombres'`.
 *
 * Ensanchar **no puede romper nada**: es una relajación (los valores de ginecología siguen entrando igual,
 * y los que ya están no se tocan). Es el mismo caso que las columnas `float` de pediatría (trampa 113),
 * pero al revés: ahí el tipo del API era más estrecho en rango, acá en largo.
 *
 * Sólo MySQL/MariaDB: en SQLite (tests) el largo no se aplica.
 */
return new class extends Migration
{
    /**
     * `columna => [largo del API, largo que necesita el origen]`, para la memoria.
     *
     * - `nombres`: 25 → 200. El `paciente_chamo.nombre` es `char(200)`.
     * - `email`: 100 → 150. El `paciente_chamo.email` es `char(120)`.
     * - `lnacimiento`: `varchar(100)` → `text`. El `paciente_chamo.l_nacimiento` es `long varchar` (texto libre).
     */
    private const COLUMNAS = [
        'nombres'     => 'VARCHAR(200) NULL',
        'email'       => 'VARCHAR(150) NULL',
        'lnacimiento' => 'TEXT NULL',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || ! Schema::hasTable('pacientes')) {
            return;
        }

        $modificaciones = [];
        foreach (self::COLUMNAS as $columna => $definicion) {
            if (Schema::hasColumn('pacientes', $columna)) {
                $modificaciones[] = "MODIFY `{$columna}` {$definicion}";
            }
        }
        if ($modificaciones === []) {
            return;
        }

        DB::statement('ALTER TABLE `pacientes` ' . implode(', ', $modificaciones));
    }

    public function down(): void
    {
        // No se vuelve atrás: con un nombre de 200 caracteres ya cargado, encoger la columna lo truncaría.
    }
};
