<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carga inicial completa desde el escritorio PowerBuilder (Fase 1 del sync del legado).
 *
 * `sync_cargas`: una fila por intento de carga de un médico. Solo puede haber una carga por
 * `reg_medico`: si falló a mitad de camino se RETOMA (no se empieza otra), y una vez completa el
 * API rechaza cargas nuevas para ese `reg_medico` (decisión del usuario, 2026-09-27).
 *
 * `sync_carga_tablas`: cuántas filas de cada tabla anunció el escritorio y cuántas llegaron. Es el
 * punto de reanudación: el escritorio manda cada lote con su posición (`desde`) y el API solo
 * acepta el lote que sigue a lo ya recibido, así reenviar un lote nunca duplica filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cargas', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->unique();
            $table->unsignedBigInteger('medico_id');
            $table->string('estado', 20)->default('en_curso'); // en_curso | completa
            $table->timestamp('iniciada_at')->nullable();
            $table->timestamp('finalizada_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_carga_tablas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_carga_id')->constrained('sync_cargas')->cascadeOnDelete();
            $table->string('tabla', 64);
            $table->unsignedInteger('filas_esperadas')->default(0);
            $table->unsignedInteger('filas_recibidas')->default(0);
            $table->unsignedInteger('filas_omitidas')->default(0);
            $table->text('columnas_ignoradas')->nullable();
            $table->timestamps();
            $table->unique(['sync_carga_id', 'tabla']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_carga_tablas');
        Schema::dropIfExists('sync_cargas');
    }
};
