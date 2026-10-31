<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La configuración de la cita online** (2026-10-30, la mitad de configuración del punto 5).
 *
 * Dos columnas **nullable** en `evolucion`, que es la fila de configuración por médico:
 *
 *  - `porcentaje_cita_online_bp`: cuánto de la cita se cobra al reservar, en **puntos base** como todo el
 *    dinero de la plataforma (`reglas_de_comision.porcentaje_bp`: `10000` = 100 %). `null` = 100 %, que es
 *    lo que hoy no está configurado para nadie. El resto se cobra en el consultorio.
 *  - `publicar_agenda`: si el médico aparece en el portal de reservas y en el bot de WhatsApp. `null` =
 *    publicada, para no vaciar el portal de golpe el día que se despliega: hoy todos los médicos con
 *    horarios aparecen y el médico la apaga desde la pantalla.
 *
 * Migración aditiva con guardas por columna: `evolucion` es una tabla del legado y esta migración no
 * toca nada más.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evolucion')) {
            return;
        }

        Schema::table('evolucion', function (Blueprint $table) {
            if (! Schema::hasColumn('evolucion', 'porcentaje_cita_online_bp')) {
                $table->unsignedSmallInteger('porcentaje_cita_online_bp')->nullable()->after('slug');
            }

            if (! Schema::hasColumn('evolucion', 'publicar_agenda')) {
                $table->boolean('publicar_agenda')->nullable()->after('porcentaje_cita_online_bp');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evolucion')) {
            return;
        }

        Schema::table('evolucion', function (Blueprint $table) {
            if (Schema::hasColumn('evolucion', 'porcentaje_cita_online_bp')) {
                $table->dropColumn('porcentaje_cita_online_bp');
            }

            if (Schema::hasColumn('evolucion', 'publicar_agenda')) {
                $table->dropColumn('publicar_agenda');
            }
        });
    }
};
