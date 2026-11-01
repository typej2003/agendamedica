<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sync_traslados`: lo que la carga inicial tuvo que mover para que **el escritorio mande**.
 *
 * Cuando el consultorio ya tiene datos, el escritorio conserva **sus** números (los de la historia no
 * pueden cambiar: ya están impresos) y el registro del API que ocupaba ese número se aparta a un rango
 * reservado. Al cerrar la carga se le da el número libre siguiente, y queda acá el rastro de todo el
 * movimiento, con una copia de la fila tal como estaba.
 *
 * `nivel` dice qué se movió:
 *  - `historia`: se renumera una historia y todo lo que cuelga de ella.
 *  - `consulta`: se renumera una consulta y lo que cuelga de ella.
 *  - `tabla`: se reemplazó la tabla entera del médico (catálogos: el escritorio trae los suyos). No hay
 *    número que mover, así que queda `a_revisar` con la copia de lo reemplazado.
 *
 * `estado`:
 *  - `pendiente`: está en el rango reservado y espera el número final (`finalizar` lo resuelve).
 *  - `reacomodado`: ya tiene su número final.
 *  - `a_revisar`: no se puede renumerar solo (catálogos) o la fila ya no existe; lo mira el administrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sync_traslados')) {
            return;
        }

        Schema::create('sync_traslados', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->index();
            $table->foreignId('sync_carga_id')->constrained('sync_cargas')->cascadeOnDelete();
            $table->string('tabla', 64);
            $table->unsignedBigInteger('registro_id')->nullable();
            $table->string('nivel', 10);                   // historia | consulta | tabla
            $table->string('motivo', 40);                  // historia_ocupada | consulta_ocupada | paciente_adoptado | catalogo_reemplazado
            $table->string('clave_original', 60)->nullable();
            $table->string('clave_temporal', 60)->nullable();
            $table->string('clave_final', 60)->nullable();
            $table->longText('copia')->nullable();         // JSON de la fila (o de las filas) tal como estaba
            $table->string('estado', 20)->default('pendiente');
            $table->timestamps();

            $table->index(['sync_carga_id', 'estado']);
            $table->index(['tabla', 'registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_traslados');
    }
};
