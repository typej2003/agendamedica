<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El **registro de los saludos de cumpleaños** (WEB-2.9): uno por intento, para que el envío automático
 * no le mande el saludo dos veces al mismo paciente el mismo día.
 *
 * **Por qué una tabla nueva y no `notificaciones_cita`.** Esa tabla es del recordatorio **de una cita**:
 * `cola_id` es `NOT NULL` —no puede quedar vacía—, tiene el índice por cita
 * (`notificaciones_cita_cola_idx`) y su nombre lo dice. Un cumpleaños **no tiene cita**: el disparador
 * es la fecha de nacimiento, no la agenda. Meterlo ahí obligaría a volver `cola_id` nullable (tocar una
 * tabla que ya está en uso) y dejaría el registro inútil para lo único que tiene que hacer: preguntar
 * "¿a este paciente ya se le mandó hoy?". Es la misma razón por la que `notificaciones_cita` no reusó
 * `sms_enviados` del legado (ver esa migración), aplicada un paso más adelante.
 *
 * **`fecha_cumple` es la clave.** No alcanza con `created_at`: es "cuándo se guardó la fila", no "qué
 * cumpleaños se saludó", y el envío automático puede reintentarse (el proveedor rechazó, o el servidor
 * se reinició a mitad de camino) — un reintento no puede convertirse en un saludo del año siguiente ni
 * al revés. Guardar la fecha del cumpleaños saludado hace que la pregunta "¿ya se le mandó el saludo de
 * **este** cumpleaños?" sea una consulta directa, y deja la fila auditable tal cual pasó.
 *
 * El índice es **único por `(paciente_id, fecha_cumple)`**: dos envíos del mismo cumpleaños no pueden
 * convivir. El comando lo usa como red de seguridad contra dos corridas solapadas —consulta el registro
 * antes de mandar y, si igual choca, lo trata como "ya estaba"— y los dos `paciente_id` y `fecha_cumple`
 * son `NOT NULL`, así que el índice se comporta igual en MySQL y en SQLite (donde los `NULL` no chocan
 * entre sí).
 *
 * Los intentos **fallidos** quedan registrados igual, con la respuesta del proveedor, para poder
 * mirarlos; lo que el índice impide es un segundo envío **del mismo paciente y del mismo cumpleaños**,
 * que es exactamente lo que no tiene que pasar. (El intento fallido se registra una sola vez por día:
 * el proveedor que rechaza no se reintenta en la misma corrida.)
 *
 * Tabla **de AppDDR**, no del legado: no viaja por el sync (el escritorio no la conoce) y por eso no
 * lleva `reg_medico` NOT NULL — va nullable y con `paciente_id` como vínculo real, igual que
 * `notificaciones_cita`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_cumple', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable();
            $table->unsignedBigInteger('paciente_id');
            // El cumpleaños que se saludó (el `fnacimiento` llevado al año que correspondía).
            $table->date('fecha_cumple');
            $table->string('canal', 20);
            $table->string('destino', 30)->nullable();
            $table->string('plantilla', 60)->nullable();
            $table->string('mensaje', 500)->nullable();
            $table->string('estado', 20);
            // Respuesta cruda del proveedor, por la misma razón que en `notificaciones_cita`: sin esto,
            // depurar un saludo que no llegó es adivinar.
            $table->text('respuesta')->nullable();
            // Quién lo mandó: `null` cuando lo mandó el servidor solo (el scheduler).
            $table->unsignedBigInteger('enviada_por')->nullable();
            $table->timestamps();

            $table->unique(['paciente_id', 'fecha_cumple'], 'notificaciones_cumple_envio_unico');
            $table->index(['reg_medico', 'created_at'], 'notificaciones_cumple_medico_idx');
            $table->index(['fecha_cumple', 'estado'], 'notificaciones_cumple_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_cumple');
    }
};
