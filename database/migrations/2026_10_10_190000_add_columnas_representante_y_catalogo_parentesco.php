<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Representantes del paciente** (WEB-3.4): los datos de contacto del adulto que responde por él, más el
 * catálogo con el que se dice **qué parentesco** tiene.
 *
 * **La tabla `representante` es del legado y no se reestructura.** El escritorio la sube por la lista
 * blanca de `config/sync_legado.php` (`representante`, línea 49) y sus columnas hoy son
 * `reg_medico`, `numhistoria`, `nombre`, `codeparentesco char(1)` y `direccion`. Lo único que se agrega
 * son **columnas nuevas nullable** (regla de `AGENTS.md` §2: una tabla que sincroniza datos reales no se
 * renombra ni se restructura): una fila del escritorio que todavía no las traiga sigue entrando igual, y
 * el camino de subida (`App\Sync\Escritorio\CambiosEscritorio::guardarGenerico`, que filtra por
 * `Schema::getColumnListing`) las acepta sin tocarse.
 *
 * **Los nombres van con los de `pacientes`**, que es donde la web ya escribe el contacto del paciente
 * (`cedula`, `telefono`, `email` — `2026_08_25_055351_create_medical_tables_schema.php:1479,1487,1494`) y
 * con las mismas longitudes: son los datos del **mismo tipo de persona** en la misma pantalla, y el
 * representante es quien presta el teléfono para el recordatorio (PLAN-WEB.md §6). No se copia `nac`: el
 * documento del representante es un dato de AppDDR que la ficha muestra tal cual, sin partirlo.
 *
 * **Una fila por historia.** El esquema real del legado (SQL Anywhere) declara
 * `PRIMARY KEY (numhistoria)` en `representante` —está en los siete `esquemas-asa/*.sql`—, así que la web
 * la trata como **un representante por paciente** y lo busca por `numhistoria`, no como una lista. La
 * migración del API **no recreó ese índice** (ver la migración de tablas faltantes, línea 594) y acá
 * **tampoco se agrega**: un índice único sobre datos que sube el escritorio podría rechazar una subida
 * que hoy entra (ADR de la tarea: la tabla legada no se reestructura), y el `updateOrCreate` de la web ya
 * garantiza una sola fila por `(reg_medico, numhistoria)`. El índice que sí se agrega es **no único**: es
 * para la consulta de la ficha, no una regla.
 *
 * **El catálogo `parentesco` es nuevo de AppDDR.** `representante.codeparentesco` es `char(1)` y **no
 * tiene catálogo en ninguna instalación del legado**: no aparece como DataWindow ni como columna escrita
 * por ninguna ventana del PowerBuilder (barrido de `Docs/MICONSULTAGI-legado/fuentes` y
 * `fuentes-pediatria`, 2026-10-10), y el pediátrico no lo usa (trabaja con `paciente_chamo.representante`
 * y `familia_bebe.ced_representante`, que son F7). Por eso la tabla es de AppDDR, **no viaja por el sync**
 * y por eso no lleva `reg_medico`: es un catálogo único, no del consultorio, como `modulos_clinicos`.
 * Los códigos que pueda haber escrito el legado son **desconocidos**; ver el comentario de
 * `App\Clinica\Pacientes\CatalogoDeParentescos` y `Docs/Wiki/07-modelo-de-datos.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('representante')) {
            Schema::table('representante', function (Blueprint $table) {
                if (! Schema::hasColumn('representante', 'cedula')) {
                    $table->string('cedula', 10)->nullable()->after('codeparentesco');
                }
                if (! Schema::hasColumn('representante', 'telefono')) {
                    $table->string('telefono', 20)->nullable()->after('cedula');
                }
                if (! Schema::hasColumn('representante', 'email')) {
                    $table->string('email', 100)->nullable()->after('telefono');
                }
            });

            // La ficha busca el representante por (reg_medico, numhistoria) —el `numhistoria` es la clave
            // real de la tabla, pero el `reg_medico` lo pone el sync y acota la consulta al consultorio—.
            // No es único a propósito: ver el encabezado.
            Schema::table('representante', function (Blueprint $table) {
                $table->index(['reg_medico', 'numhistoria'], 'representante_medico_historia_idx');
            });
        }

        if (! Schema::hasTable('parentesco')) {
            Schema::create('parentesco', function (Blueprint $table) {
                $table->id();
                // Un carácter porque es lo que mide `representante.codeparentesco` en el legado.
                $table->string('codigo', 1)->unique('parentesco_codigo_unico');
                $table->string('nombre', 40);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Se revierte sin perder datos del legado: las tres columnas nuevas nacieron vacías y el catálogo lo
     * vuelve a sembrar `ParentescoSeeder`. Es lo que corre `migrate:fresh` en los tests.
     */
    public function down(): void
    {
        if (Schema::hasTable('representante')) {
            Schema::table('representante', function (Blueprint $table) {
                $table->dropIndex('representante_medico_historia_idx');
            });
            Schema::table('representante', function (Blueprint $table) {
                $table->dropColumn(['cedula', 'telefono', 'email']);
            });
        }

        Schema::dropIfExists('parentesco');
    }
};
