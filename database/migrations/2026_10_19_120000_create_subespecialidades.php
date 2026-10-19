<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Subespecialidades (postgrados) de una especialidad** (decisión del usuario, 2026-10-19).
 *
 * Una especialidad es lo que el médico estudió (Pediatría, Nefrología); una subespecialidad es el
 * postgrado que hizo **dentro** de esa especialidad (Nefrología pediátrica es un postgrado de Pediatría,
 * no una especialidad aparte). Un médico puede tener varias especialidades y, dentro de cada una, más de
 * un postgrado; los postgrados de la especialidad que está trabajando **se suman** para encender módulos
 * y secciones de la consulta.
 *
 * Tres tablas, con el mismo patrón que `modulos_clinicos` / `especialidad_modulo`:
 *
 *  - `subespecialidades`: el catálogo. `specialty_id` es la especialidad de la que cuelga y `slug` es la
 *    clave estable que usan los manifiestos (`config/especialidades/<especialidad>.php`). `codigo` es el
 *    `codeespecial` del legado cuando la instalación la tiene como código propio (cirugía pediátrica `019`,
 *    neurología pediátrica `024`).
 *  - `medico_subespecialidad`: qué postgrados tiene cada médico. El postgrado ya dice su especialidad,
 *    pero se valida al asignarlo (un postgrado de Pediatría no se le da a un médico que no es pediatra).
 *  - `subespecialidad_modulo`: qué **módulos del menú** enciende cada postgrado, con las mismas columnas del
 *    pivote de especialidad (`implementado`, `visible_para`, `orden`).
 *
 * Las **secciones de la consulta** que enciende un postgrado (`indicadores_incidentes`, la espirometría…)
 * no van en estas tablas: se declaran en el manifiesto de la especialidad, junto a la lista de ítems de su
 * consulta, y se leen del contexto (ver `SeccionesDeLaConsulta` y `AlcanceDeLaEspecialidad`).
 *
 * Todo con guardas (`hasTable`) para que sea inocuo en una base que ya las tenga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subespecialidades')) {
            Schema::create('subespecialidades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('specialty_id');
                $table->string('slug', 80)->unique();
                $table->string('nombre', 120);
                // `codeespecial` del catálogo `especial` del legado, cuando la instalación la tiene como
                // código propio (019 cirugía pediátrica, 024 neurología pediátrica).
                $table->string('codigo', 10)->nullable();
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->index('specialty_id');
            });
        }

        if (! Schema::hasTable('medico_subespecialidad')) {
            Schema::create('medico_subespecialidad', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('medico_id');
                $table->unsignedBigInteger('subespecialidad_id');
                $table->timestamps();

                $table->unique(['medico_id', 'subespecialidad_id']);
                $table->index('subespecialidad_id');
            });
        }

        if (! Schema::hasTable('subespecialidad_modulo')) {
            Schema::create('subespecialidad_modulo', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subespecialidad_id');
                $table->unsignedBigInteger('modulo_id');
                // `false` = declarado en el manifiesto pero todavía no construido: NO se muestra en el menú.
                $table->boolean('implementado')->default(false);
                // Roles que lo ven (JSON). `null` = todos los del consultorio (médico y secretaría).
                $table->json('visible_para')->nullable();
                $table->unsignedSmallInteger('orden')->default(0);
                $table->timestamps();

                $table->unique(['subespecialidad_id', 'modulo_id'], 'subespecialidad_modulo_unico');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subespecialidad_modulo');
        Schema::dropIfExists('medico_subespecialidad');
        Schema::dropIfExists('subespecialidades');
    }
};
