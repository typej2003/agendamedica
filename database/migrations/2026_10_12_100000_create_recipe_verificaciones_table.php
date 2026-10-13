<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La **verificación pública del récipe** (WEB-5.6): el QR de la hoja impresa lleva a una página pública donde una
 * farmacia comprueba que el récipe es auténtico y fue emitido desde Doctorisimo.app.
 *
 * Una fila por récipe impreso desde la web. **No guarda una copia del récipe**: sólo lo identifica
 * (`reg_medico` + `nrohistoria` + `nroconsulta` + `tipo` + `recipe`) y le da un `token` secreto, de modo que la
 * página siempre lee el récipe **vivo** (si el médico lo corrige se ve lo nuevo; si lo elimina, la página lo dice).
 *
 *  - `token`: 32 bytes aleatorios en base64url (43 caracteres). Es una **capacidad pública**: quien lo tiene ve la
 *    página, así que no se adivina, no se loguea y no entra en `sync_changes`.
 *  - `codigo`: 8 caracteres legibles (sin 0/O/1/I/L) para tipear si el QR no se lee.
 *  - `tipo`: `paciente` o `pareja`. El de la pareja es uno por consulta (no tiene número): `recipe` vale 1, para que
 *    el índice único funcione igual en MySQL y en SQLite (donde los `NULL` no chocan entre sí).
 *  - `revocado_en`: el médico anuló el QR. Se puede reactivar.
 *
 * Tabla **de AppDDR**, no del legado: el escritorio no la conoce, no entra en `config/sync_legado.php` y el móvil no la
 * baja. `revocado_en` es `dateTime` y no `timestamp` por la trampa 10 de `TRAMPAS.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_verificaciones', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('codigo', 12)->unique();
            $table->string('reg_medico', 20);
            $table->unsignedBigInteger('medico_id')->nullable();
            $table->unsignedInteger('nrohistoria');
            $table->unsignedInteger('nroconsulta');
            $table->string('tipo', 10);
            $table->unsignedInteger('recipe')->default(1);
            $table->dateTime('revocado_en')->nullable();
            $table->timestamps();

            $table->unique(['reg_medico', 'nrohistoria', 'nroconsulta', 'tipo', 'recipe'], 'recipe_verificaciones_recipe_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_verificaciones');
    }
};
