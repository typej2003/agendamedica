<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las invitaciones por correo del programa de referidos** (O-50, 2026-11-02).
 *
 * Una fila por **correo invitado y código**: es lo que permite reenviar sin perder el rastro («a este ya
 * se le mandó el 12/10») y contar los envíos del día para el tope de 10. Tabla de AppDDR: no viaja por el
 * sync y no toca ninguna tabla del legado.
 *
 * `reg_medico` está desnormalizado a propósito: el tope es por médico y un día, y el código puede
 * regenerarse (cambia `codigo_id`) sin que eso reinicie el contador del día.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invitaciones_de_referido')) {
            return;
        }

        Schema::create('invitaciones_de_referido', function (Blueprint $table) {
            $table->id();
            $table->foreignId('codigo_id')->nullable()->constrained('codigos_de_referido')->nullOnDelete();
            // El consultorio que invita: es la llave del tope diario.
            $table->string('reg_medico', 50);
            $table->string('email', 191);
            // Quién la mandó desde la web (usuario), y cuándo fue la primera vez.
            $table->unsignedBigInteger('enviada_por')->nullable();
            $table->timestamp('enviada_el')->nullable();
            // Cuántas veces se mandó a este correo y cuándo fue la última.
            $table->unsignedInteger('veces')->default(0);
            $table->timestamp('ultima_vez')->nullable();
            // Se completa cuando esa persona se registra con el link (atribución del código).
            $table->timestamp('registrada_el')->nullable();
            $table->timestamps();

            $table->unique(['codigo_id', 'email'], 'invitaciones_de_referido_unica');
            $table->index(['reg_medico', 'ultima_vez'], 'invitaciones_de_referido_tope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones_de_referido');
    }
};
