<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ayúdanos a mejorar" (ROADMAP §9): los comentarios que nos manda quien usa el sistema —un bug, una
 * sugerencia o algo que le falta— y la respuesta que les damos.
 *
 * Tabla **nueva de AppDDR**, no del legado: no entra al sync del escritorio ni se sube desde el app, así que
 * se diseña libre (no toca ninguna tabla del legado). El `origen` queda preparado para cuando el app móvil
 * también mande comentarios; hoy escribe la web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comentarios', function (Blueprint $table) {
            $table->id();

            // Quién lo escribió. Sin clave foránea a propósito: el comentario no se borra si se va la cuenta
            // (es el rastro de lo que nos dijo), y el rol queda copiado por el mismo motivo.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('medico_id')->nullable();
            $table->string('reg_medico', 20)->nullable();
            $table->string('rol', 20)->nullable();

            $table->string('origen', 10)->default('web');
            $table->string('tipo', 20);
            $table->text('mensaje');

            // Lo que ayuda a reproducir un error sin tener que preguntar: versión, pantalla, navegador. Va
            // como json para no inventar una columna por cada cosa que se nos ocurra después.
            $table->json('contexto')->nullable();

            $table->string('estado', 20)->default('nuevo');

            // Una sola respuesta por comentario (si algún día hace falta un hilo, se le agrega su tabla).
            $table->text('respuesta')->nullable();
            $table->unsignedBigInteger('respondido_por')->nullable();
            $table->timestamp('respondido_el')->nullable();

            $table->timestamps();

            // La bandeja se abre siempre por estado, de lo más nuevo a lo más viejo.
            $table->index(['estado', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comentarios');
    }
};
