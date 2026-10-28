<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `configuracion_api`: la **configuración interna del servidor** en clave/valor.
 *
 * La primera clave es `version_datos`, la versión de los comandos de datos que se corren una sola vez
 * (`php artisan datos:actualizar`): empieza en 0 y la mueve ese comando. La tabla queda para las demás
 * configuraciones internas que hagan falta (no para datos clínicos ni para lo que viaja por el sync).
 *
 * ⚠️ Los valores se guardan en **texto claro**. Si algún día hay que guardar un secreto, va cifrado
 * aparte, no acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Con guarda: si una corrida anterior creó la tabla y murió antes de anotar la migración, la
        // siguiente no puede volver a crearla (la misma razón que las otras tablas nuevas del repo).
        if (! Schema::hasTable('configuracion_api')) {
            Schema::create('configuracion_api', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 100)->unique();
                $table->text('valor')->nullable();
                $table->timestamps();
            });
        }

        // La fila de la versión de datos, en 0. No se pisa si ya existe: una base desplegada que vuelve
        // a correr la migración no puede perder la versión que ya alcanzó.
        if (Schema::hasTable('configuracion_api')
            && Schema::hasColumn('configuracion_api', 'clave')
            && Schema::hasColumn('configuracion_api', 'valor')
            && ! DB::table('configuracion_api')->where('clave', 'version_datos')->exists()) {
            DB::table('configuracion_api')->insert([
                'clave' => 'version_datos',
                'valor' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_api');
    }
};
