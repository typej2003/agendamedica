<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pacientes.numhistoria` deja de ser obligatoria, si la tabla la tiene.
 *
 * El esquema del repo no trae esa columna (el número de historia vive en `medico_pacientes` y en
 * `historias`), pero las bases que se armaron cargando el dump del legado sí: `INT NOT NULL`, sin valor
 * por defecto. Ahí, crear un paciente sin ese número —el app, el panel y los endpoints viejos de
 * sincronización lo hacen— tiraba la petición con HTTP 500: "Field 'numhistoria' doesn't have a default
 * value" (visto el 2026-09-27 contra mercadoexpres.com al subir pacientes nuevos). La carga inicial y la
 * sincronización de cambios del escritorio ya la llenan cuando existe (`PacientesLegado`), así que solo
 * se relaja la restricción: los datos y el tipo no cambian.
 *
 * Se detecta sola: si la columna no existe o ya admite NULL no hace nada, de modo que es seguro correrla
 * en cualquier base. Sin doctrine/dbal no hay `->change()`, y SQLite (la base local) no tiene la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('pacientes') || ! Schema::hasColumn('pacientes', 'numhistoria')) {
            return;
        }

        $columna = DB::selectOne(
            'SELECT column_type AS tipo, is_nullable AS admite_null FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['pacientes', 'numhistoria']
        );

        if ($columna && strtoupper((string) $columna->admite_null) === 'NO') {
            DB::statement($this->sentencia((string) $columna->tipo));
        }
    }

    public function down(): void
    {
        // No se vuelve a exigir: las filas que ya entraron sin número quedarían fuera de la restricción.
    }

    /** Conserva el tipo que tenga la base (`int(11)`, `int`…) y solo cambia a que admita NULL. */
    public function sentencia(string $tipo): string
    {
        return "ALTER TABLE pacientes MODIFY numhistoria {$tipo} NULL";
    }
};
