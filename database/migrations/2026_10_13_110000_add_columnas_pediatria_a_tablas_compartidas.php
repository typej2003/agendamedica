<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas que la base de **pediatría** tiene en tablas que el API ya tenía (compartidas con
 * ginecología). Sin ellas, el sync de pediatría las informaría como `columnas_ignoradas` y el dato
 * se perdería en silencio.
 *
 * Salen del diff por columna del esquema real (`Docs/MICONSULTAGI-legado/diff-columnas-especialidades.md`,
 * generado con `bridge/tools/diff-columnas-especialidades.php`), sección B de pediatría: mismas
 * columnas, tipos y nulabilidad que el escritorio. **Todas nullable**: el escritorio de ginecología no
 * las manda y la fila tiene que seguir entrando.
 *
 * ⚠️ **`examen_fisico_nuevo` es la misma tabla con otra clave**: el escritorio de pediatría la llavea
 * por `nrohistoria` + `nroconsulta` y el API por `historia` + `consulta` (que es como la escriben la
 * web y ginecología). No se agregan las dos columnas nuevas: el sync las traduce con
 * `columnas_equivalentes` (`config/sync_legado.php`), porque dos columnas para el mismo dato dejarían
 * las filas de pediatría con la clave en un lado y las pantallas leyendo el otro.
 *
 * Tampoco se agrega `pacientes.numhistoria` (el escritorio la tiene): en el API el número de historia
 * vive en `historias` / `medico_pacientes` y el camino del sync es `PacientesLegado`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La consulta: el resumen de la historia clínica, la evaluación, los estudios de tórax y las
        // pruebas respiratorias (la espirometría con sus dos medidas).
        Schema::table('consultas', function (Blueprint $table) {
            foreach (['resumen', 'evaluacion', 'rayosx', 'espirometria', 'broncoscopia', 'detalle_exa'] as $columna) {
                if (! Schema::hasColumn('consultas', $columna)) {
                    $table->text($columna)->nullable();
                }
            }
            foreach (['espirometria_pef', 'espirometria_fev1'] as $columna) {
                if (! Schema::hasColumn('consultas', $columna)) {
                    $table->float($columna)->nullable();
                }
            }
        });

        // El examen físico por regiones del paciente pediátrico (el de ginecología es el examen
        // ginecológico: mamas, especuloscopía, tacto…).
        foreach (['examen_fisico', 'examen_fisico_nuevo'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                foreach (['cabeza', 'torax', 'pelvis', 'genitales', 'brazos', 'piernas', 'otros', 'fisico_resumen'] as $columna) {
                    if (! Schema::hasColumn($tabla, $columna)) {
                        $table->text($columna)->nullable();
                    }
                }
            });
        }

        // El catálogo de motivos de consulta: pediatría lo clasifica por tipo.
        if (! Schema::hasColumn('motivos_consulta', 'tipo')) {
            Schema::table('motivos_consulta', function (Blueprint $table) {
                $table->string('tipo', 2)->nullable();
            });
        }

        // Los medicamentos del tratamiento: pediatría guarda la dosis (cantidad, cada cuánto, por
        // cuánto tiempo, su dosificación y la presión venosa central).
        Schema::table('recipe_grupo_detalle', function (Blueprint $table) {
            foreach (['dosis', 'cada', 'pvc', 'pvs', 'concentracion'] as $columna) {
                if (! Schema::hasColumn('recipe_grupo_detalle', $columna)) {
                    $table->float($columna)->nullable();
                }
            }
            if (! Schema::hasColumn('recipe_grupo_detalle', 'durante')) {
                $table->integer('durante')->nullable();
            }
            if (! Schema::hasColumn('recipe_grupo_detalle', 'dosificacion')) {
                $table->text('dosificacion')->nullable();
            }
        });
    }

    /** No se borran: en desarrollo alguna de estas columnas puede ser de una base cargada del dump. */
    public function down(): void
    {
    }
};
