<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un `office` pasa a ser **el consultorio de un médico en una sede**, con la configuración de
 * agenda de ese lugar.
 *
 * Por qué acá y no en `evolucion`: un médico atiende en varias sedes con horarios y hasta
 * modalidades distintas (el caso real: hospital público por orden de llegada en la mañana,
 * clínica privada con hora de cita en la tarde, y los sábados un pueblo a dos horas). `evolucion`
 * guarda **un solo rango por día y sin distinguir sede**, así que no puede expresarlo.
 *
 * La tabla ya existía como "consultorio físico del centro" (`office_number`, `phone`) y se
 * reutiliza: un consultorio es, justamente, dónde atiende un médico en esa clínica. `medico_id`
 * queda nullable porque las filas ya sembradas no lo tienen.
 *
 * `schedule` (texto libre, del tipo "Lunes a Viernes de 8:00 AM a 4:00 PM") **queda obsoleta**:
 * es descriptiva y nadie la parsea. El horario de verdad vive en `office_schedules`, una fila por
 * bloque. No se elimina para no cambiarle el payload a `refresh-data`, que la serializa con
 * `with('offices')` y todavía tiene clientes.
 */
class AddAgendaConfigToOffices extends Migration
{
    public const MODALIDAD_HORA = 'hora_cita';
    public const MODALIDAD_ORDEN = 'orden_llegada';

    public function up()
    {
        Schema::table('offices', function (Blueprint $table) {
            if (!Schema::hasColumn('offices', 'medico_id')) {
                $table->unsignedBigInteger('medico_id')->nullable()->after('medical_center_id');
                $table->foreign('medico_id')->references('id')->on('medicos')->onDelete('cascade');
            }
            if (!Schema::hasColumn('offices', 'reg_medico')) {
                // Denormalizado a propósito: es el discriminador de tenant con el que el sync
                // arma el delta, y resolverlo por join en cada sincronización sería dar la vuelta.
                $table->string('reg_medico', 20)->nullable()->after('medico_id');
            }
            if (!Schema::hasColumn('offices', 'modalidad')) {
                $table->string('modalidad', 20)->default(self::MODALIDAD_HORA)->after('reg_medico');
            }
            if (!Schema::hasColumn('offices', 'duracion_cita')) {
                $table->integer('duracion_cita')->nullable()->after('modalidad');
            }
            if (!Schema::hasColumn('offices', 'activo')) {
                $table->boolean('activo')->default(true)->after('duracion_cita');
            }
        });
    }

    public function down()
    {
        Schema::table('offices', function (Blueprint $table) {
            foreach (['modalidad', 'duracion_cita', 'activo', 'reg_medico'] as $columna) {
                if (Schema::hasColumn('offices', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        if (Schema::hasColumn('offices', 'medico_id')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->dropForeign(['medico_id']);
                $table->dropColumn('medico_id');
            });
        }
    }
}
