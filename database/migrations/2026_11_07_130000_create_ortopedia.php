<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **`ortopedia`** (Traumatología, 2026-11-07): la plantilla ortopédica de la consulta —botas, zapatos, soporte,
 * contrafuerte, tacón y suela, bordes, aparatos y observación—.
 *
 * Definición tomada del esquema real (`Docs/MICONSULTAGI-legado/esquemas-asa/traumatologia.sql`), con `id`,
 * `reg_medico` y fechas como el resto de las tablas del legado. Clave del escritorio: `nrohistoria` + `nroconsulta`.
 * Las casillas son `char(1)` con `1`/`0`.
 *
 * `hasTable` como el resto; `down()` no borra nada (misma nota que las otras migraciones aditivas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ortopedia')) {
            return;
        }

        Schema::create('ortopedia', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');

            $table->char('botas', 1)->nullable();
            $table->char('zapatos', 1)->nullable();
            $table->char('soporte', 6)->nullable();
            $table->char('thomas', 8)->nullable();
            $table->char('normal', 1)->nullable();
            $table->char('tacon_int', 5)->nullable();
            $table->char('tacon_ext', 5)->nullable();
            $table->char('suela_int', 5)->nullable();
            $table->char('suela_ext', 5)->nullable();
            $table->char('borde_int_rect', 1)->nullable();
            $table->char('borde_int_abdu', 1)->nullable();
            $table->char('suela_normal', 1)->nullable();
            $table->char('suela_antirres', 1)->nullable();
            $table->char('plantilla', 1)->nullable();
            $table->char('aparatos', 20)->nullable();
            $table->text('observacion')->nullable();
            $table->char('aae', 15)->nullable();
            $table->char('api', 7)->nullable();

            $table->timestamps();

            $table->index(['reg_medico', 'nrohistoria', 'nroconsulta'], 'ortopedia_consulta');
        });
    }

    public function down(): void
    {
        // No borra nada: misma regla que las migraciones aditivas del legado.
    }
};
