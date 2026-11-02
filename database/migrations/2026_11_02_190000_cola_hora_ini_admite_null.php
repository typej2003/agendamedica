<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.hora_ini` deja de ser obligatoria: el escritorio de medicina interna agenda **sin hora**.
 *
 * Los ocho esquemas del legado (`Docs/MICONSULTAGI-legado/esquemas-asa/*.sql`) declaran
 * `hora_ini time NULL`; el API la creó `NOT NULL` y eso rompía la carga inicial del internista:
 * «Integrity constraint violation: 1048 Column 'hora_ini' cannot be null» (visto en producción el
 * 2026-11-02, a mitad de la subida de `cola`). En la base real de un internista, 3.742 de 19.428
 * citas no tienen hora (desde 2011 hasta 2026): su agenda es por orden de llegada, no por reloj.
 *
 * Solo se relaja la restricción: el tipo y los datos no cambian y ninguna fila existente se toca.
 * La lectura ya estaba preparada (`CitaDeAgenda::$horaIni` admite null y `horaCorta()` muestra «—»);
 * lo que cambia es que ahora se pueden **guardar**.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cola') || ! Schema::hasColumn('cola', 'hora_ini')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Sin doctrine/dbal, y para no tocar la tabla si ya admite NULL: se conserva el tipo real
            // de la base (`time`, `time(6)`…) y solo se quita el NOT NULL.
            $columna = DB::selectOne(
                'SELECT column_type AS tipo, is_nullable AS admite_null FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['cola', 'hora_ini']
            );

            if ($columna && strtoupper((string) $columna->admite_null) === 'NO') {
                DB::statement("ALTER TABLE cola MODIFY hora_ini {$columna->tipo} NULL");
            }

            return;
        }

        // SQLite (la suite): Laravel reconstruye la tabla con la columna relajada.
        Schema::table('cola', function (Blueprint $table) {
            $table->time('hora_ini')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No se vuelve a exigir: las citas que ya entraron sin hora quedarían fuera de la restricción.
    }
};
