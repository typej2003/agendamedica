<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las solicitudes de baja de cuenta** (2026-10-30).
 *
 * Tabla nueva de AppDDR (no del legado), así que se diseña libre. Una fila por pedido:
 *
 *  - `pendiente`: se pidió desde `/eliminar-cuenta` y **todavía no se confirmó** desde el correo. No
 *    arranca ningún plazo: cualquiera puede escribir el correo de otro en el formulario, así que el
 *    pedido no vale hasta que quien lo pidió abre el enlace que llega a esa cuenta.
 *  - `programada`: confirmada. `ejecutar_despues_de` son **30 días** después de la confirmación.
 *  - `cancelada`: se echó atrás. Hay dos caminos y se guarda cuál (`motivo`): **iniciar sesión** o el
 *    **enlace de cancelación** del correo.
 *  - `ejecutada`: la baja se hizo. Se guarda la fecha y **no se borra la fila**: es el registro de que la
 *    cuenta se dio de baja (y de que no fue un borrado sin rastro).
 *
 * `user_id` va sin clave foránea, como el resto de las tablas del proyecto frente a `users`: la baja
 * borra la cuenta y la fila de la solicitud tiene que sobrevivirle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('solicitudes_de_baja')) {
            return;
        }

        Schema::create('solicitudes_de_baja', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('email', 190);
            // El token del enlace del correo: 64 hex. Único, y es lo único que autoriza confirmar o cancelar.
            $table->string('token', 64)->unique();
            $table->string('estado', 20)->default('pendiente')->index();
            $table->timestamp('confirmada_en')->nullable();
            $table->timestamp('cancelada_en')->nullable();
            $table->timestamp('ejecutada_en')->nullable();
            // Hasta cuándo se puede cancelar: la fecha en que la baja se ejecuta.
            $table->timestamp('ejecutar_despues_de')->nullable()->index();
            // `inicio-de-sesion` o `enlace`, cuando se cancela.
            $table->string('motivo', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_de_baja');
    }
};
