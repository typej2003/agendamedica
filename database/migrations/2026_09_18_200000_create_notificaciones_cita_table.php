<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de notificaciones enviadas por una cita (WhatsApp hoy, SMS cuando haya proveedor).
 *
 * **Por qué una tabla nueva y no `sms_enviados` del legado**: esa tabla existe en el esquema
 * legado pero está **vacía** (cero filas en todo el dump), tiene forma de SMS (`numero
 * VARCHAR(11)`, `mensaje VARCHAR(150)`) y columnas sin semántica conocida (`conta` NOT NULL,
 * `tipo VARCHAR(1)`). Reusarla sería inventarle significado a campos ajenos; además no engancha
 * con la cita (`cola`), sino con historia/consulta.
 *
 * El nombre se verificó contra el esquema legado completo antes de crearlo — es la lección del
 * Paso 10 (`recipes` ya existía con otro nombre de columna y rompió contra producción).
 */
class CreateNotificacionesCitaTable extends Migration
{
    public function up()
    {
        Schema::create('notificaciones_cita', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable();
            $table->unsignedBigInteger('cola_id');
            $table->string('canal', 20);
            $table->string('destino', 30)->nullable();
            $table->string('plantilla', 60)->nullable();
            $table->string('mensaje', 500)->nullable();
            $table->string('estado', 20);
            // Respuesta cruda del proveedor: sin esto, depurar un envío fallido contra Meta es
            // adivinar. Es también lo único que permite reclamar un mensaje no entregado.
            $table->text('respuesta')->nullable();
            $table->unsignedBigInteger('enviada_por')->nullable();
            $table->timestamps();

            $table->index(['cola_id', 'created_at'], 'notificaciones_cita_cola_idx');
            $table->index(['reg_medico', 'created_at'], 'notificaciones_cita_medico_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notificaciones_cita');
    }
}
