<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El doctor de la consulta** (2026-10-20): `consultas.medico_id`.
 *
 * Hasta acá la consulta sólo decía a qué **consultorio** pertenecía (`reg_medico`) y, en la columna legacy
 * `medico`, la `clave` de `evolucion` con la que el escritorio identifica al doctor —que la web dejaba nula—.
 * Con el centro como tenant, eso no alcanza: quien firma la hoja es **el doctor que atendió**, y
 * `App\Clinica\Impresion\MedicoQueFirma` lo busca primero en esta columna (`medico_id`, el `medicos.id`) y
 * después en la clave legacy. Es la misma pareja de columnas que `cola` (Fase 3).
 *
 * `consultas` es una tabla del **legado** (tiene INSERTs en el dump y la sube el escritorio): la columna es
 * **nullable** y no se toca ninguna existente. El escritorio no la manda —el camino de
 * `App\Sync\Escritorio\CambiosEscritorio` ya la completaba con el médico de la carga cuando la columna
 * existe—, así que las filas viejas quedan en `null` y el firmante cae en la clave o en el médico del
 * contexto, como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consultas') && ! Schema::hasColumn('consultas', 'medico_id')) {
            Schema::table('consultas', function (Blueprint $table) {
                $table->unsignedBigInteger('medico_id')->nullable();
            });
        }
    }

    /** Se deshace: es una columna nueva de AppDDR y ninguna fila del legado depende de ella. */
    public function down(): void
    {
        if (Schema::hasColumn('consultas', 'medico_id')) {
            Schema::table('consultas', function (Blueprint $table) {
                $table->dropColumn('medico_id');
            });
        }
    }
};
