<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Una sola imagen editable por examen** (O-09 y O-10): la tarjeta de la colposcopía —y la de la histeroscopía— tiene
 * **dos modos excluyentes**, la imagen predeterminada (el esquema sobre el que se dibuja) y la foto real del estudio
 * (videocolposcopio / videohisteroscopio), y el médico puede **marcar encima de la foto**.
 *
 * Por eso la tabla de A-13 deja de ser sólo de la colposcopía y pasa a ser la de **el trazo del examen**:
 *
 *  - `examen` es la solapa: `colposcopia` (y, desde O-10, `histeroscopia`).
 *  - `clave` identifica **lo que hay debajo del trazo**: `esquema:nulipara`, `esquema:multipara`, `esquema:utero` o
 *    `foto:<id de imagen_consulta>`. Es la que hace única la fila en las dos bases, porque un índice único con un `NULL`
 *    adentro no compara nada en MySQL (las filas del esquema no tienen foto y las de la foto no tienen variante).
 *  - `imagen_id` es la **referencia a la foto** sobre la que se dibujó: el trazo queda atado a esa imagen y la foto **no
 *    se copia** (el archivo sigue siendo el de `imagen_consulta`).
 *  - `variante` y `base` son del esquema y quedan **nullable** (una foto no tiene ninguna de las dos).
 *
 * Y se agrega la tabla de **la elección** (`elecciones_de_imagen_del_examen`): una fila por consulta y examen que dice
 * cuál de los dos modos se ve y se imprime y —cuando hay varias fotos— cuál de ellas se está marcando.
 *
 * Las dos tablas son **nuevas de AppDDR** (no vienen del legado y no entran al sync del escritorio).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. El trazo de A-13 pasa a ser el trazo del examen. La tabla se recrea —en vez de renombrarla y sumarle
        //    columnas— porque `variante` y `base` tienen que dejar de ser obligatorias y quitar esa obligación en SQLite
        //    necesita `doctrine/dbal`, que este repo no instala.
        Schema::create('dibujos_del_examen', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            // Como `imagen_consulta`: el número de historia y el de la consulta dentro de la historia.
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('examen', 20); // colposcopia | histeroscopia
            $table->string('clave', 40); // esquema:<variante> | foto:<id>
            // La foto sobre la que se dibujó, cuando el lienzo es una foto (`imagen_consulta.id`).
            $table->unsignedBigInteger('imagen_id')->nullable();
            // El esquema: cuál de sus variantes (nulipara, multipara, utero) y con qué imagen base.
            $table->string('variante', 12)->nullable();
            $table->string('base', 12)->nullable();
            // Ruta relativa al disco `clinica`. Es **nullable** a propósito: la fila del esquema se queda con la base
            // elegida aunque el médico todavía no haya dibujado (o haya borrado todo el trazo).
            $table->string('archivo', 255)->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'examen', 'clave'], 'dibujos_del_examen_unico');
            $table->index('imagen_id', 'dibujos_del_examen_imagen_idx');
        });

        // 2. Lo que ya estaba dibujado es el esquema de la colposcopía, una fila por variante.
        if (Schema::hasTable('dibujos_de_colposcopia')) {
            foreach (DB::table('dibujos_de_colposcopia')->get() as $fila) {
                DB::table('dibujos_del_examen')->insert([
                    'reg_medico' => $fila->reg_medico,
                    'nrohistoria' => $fila->nrohistoria,
                    'nroconsulta' => $fila->nroconsulta,
                    'examen' => 'colposcopia',
                    'clave' => 'esquema:' . $fila->variante,
                    'variante' => $fila->variante,
                    'base' => $fila->base,
                    'archivo' => $fila->archivo,
                    'created_at' => $fila->created_at,
                    'updated_at' => $fila->updated_at,
                ]);
            }

            Schema::drop('dibujos_de_colposcopia');
        }

        // 3. La elección: cuál de las dos imágenes del examen se ve y se imprime.
        Schema::create('elecciones_de_imagen_del_examen', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('examen', 20); // colposcopia | histeroscopia
            $table->string('modo', 10)->default('esquema'); // esquema | foto
            // La foto elegida cuando el modo es la foto: una de las del estudio, o la única que hay.
            $table->unsignedBigInteger('imagen_id')->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'examen'], 'elecciones_de_imagen_unico');
        });
    }

    /**
     * La vuelta deja la tabla de A-13 como estaba pero **vacía**: se recrea en vez de borrarle columnas, por lo mismo que
     * arriba (`doctrine/dbal`), y perder los trazos de una tarea sin desplegar es mejor que una migración que no se puede
     * deshacer en la base de desarrollo.
     */
    public function down(): void
    {
        Schema::dropIfExists('elecciones_de_imagen_del_examen');
        Schema::dropIfExists('dibujos_del_examen');

        Schema::create('dibujos_de_colposcopia', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('variante', 12);
            $table->string('base', 12)->default('vector');
            $table->string('archivo', 255)->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'variante'], 'dibujos_de_colposcopia_unico');
        });
    }
};
