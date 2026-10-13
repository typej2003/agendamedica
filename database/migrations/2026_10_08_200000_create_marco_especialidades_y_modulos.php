<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marco multi-especialidad de la web (PLAN-WEB.md, regla R2).
 *
 * - `specialties.codigo` / `activo`: identificador corto de la especialidad en AppDDR y si está en uso.
 * - `modulos_clinicos`: catálogo de módulos (agenda, pacientes, consulta, ecografías…).
 * - `especialidad_modulo`: qué módulos tiene cada especialidad, si ya está **implementado** y qué roles
 *   lo ven. El menú de la web se arma con esto, nunca con un `if` por especialidad.
 * - `medico_registros.user_id`: permite dar acceso a los datos de un `reg_medico` a una cuenta que **no
 *   es un médico** (la secretaria), que hasta ahora no tenía forma de quedar vinculada a un consultorio.
 *
 * Todo con guardas (`hasTable`/`hasColumn`) para que sea inocuo en una base que ya las tenga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialties')) {
            Schema::table('specialties', function (Blueprint $table) {
                if (! Schema::hasColumn('specialties', 'codigo')) {
                    $table->string('codigo', 10)->nullable()->after('name');
                }
                if (! Schema::hasColumn('specialties', 'activo')) {
                    $table->boolean('activo')->default(true)->after('description');
                }
            });
        }

        if (! Schema::hasTable('modulos_clinicos')) {
            Schema::create('modulos_clinicos', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 60)->unique();
                $table->string('nombre', 120);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('especialidad_modulo')) {
            Schema::create('especialidad_modulo', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('specialty_id');
                $table->unsignedBigInteger('modulo_id');
                // `false` = declarado en el manifiesto pero todavía no construido: NO se muestra en el menú.
                $table->boolean('implementado')->default(false);
                // Roles que lo ven (JSON). `null` = todos los del consultorio (médico y secretaría).
                $table->json('visible_para')->nullable();
                $table->unsignedSmallInteger('orden')->default(0);
                $table->timestamps();

                $table->unique(['specialty_id', 'modulo_id']);
            });
        }

        if (Schema::hasTable('medico_registros') && ! Schema::hasColumn('medico_registros', 'user_id')) {
            Schema::table('medico_registros', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('medico_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medico_registros') && Schema::hasColumn('medico_registros', 'user_id')) {
            Schema::table('medico_registros', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        Schema::dropIfExists('especialidad_modulo');
        Schema::dropIfExists('modulos_clinicos');

        if (Schema::hasTable('specialties')) {
            Schema::table('specialties', function (Blueprint $table) {
                foreach (['codigo', 'activo'] as $columna) {
                    if (Schema::hasColumn('specialties', $columna)) {
                        $table->dropColumn($columna);
                    }
                }
            });
        }
    }
};
