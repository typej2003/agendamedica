<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `motivo_cita.admite_varias_citas_pendientes` — la excepción a la regla de la cita única (WEB-2.17;
 * decisión de Alexander, 2026-10-10).
 *
 * La regla del consultorio es que un paciente tenga **una** cita pendiente por consultorio: la web
 * pregunta qué hacer con la anterior antes de agendar (WEB-2.2) y el escritorio borra las futuras
 * (`delete from cola where fecha > today() and numhistoria = :historia`, wiki `02-modulo-agenda.md` §4
 * confirmado en 6 ventanas). Hay servicios que son la excepción —masajistas, terapias, donde el paciente
 * viene varias veces— y quien lo sabe es **el servicio**, no el consultorio: por eso la marca va en el
 * motivo y no en `offices` (`MedicalCenter`).
 *
 * El nombre dice la propiedad del motivo tal como se usa: admite varias citas **pendientes**, que es la
 * única regla que existe (una cita pasada sin atender no cuenta, ver `ArmadorDeAgenda::citaPendiente`).
 * Se descartaron `varias_citas` —se leería como "el motivo tiene varias citas"— y un contador, que
 * habría que mantener sincronizado sin que nadie lo consulte.
 *
 * Nullable (regla del proyecto para las tablas del legado, `PLAN-WEB.md` R4): `null` y `false` significan
 * lo mismo —la regla común—, así que las filas que ya existen y las que sigue subiendo el escritorio
 * quedan sin marca y el comportamiento no cambia; la pantalla de motivos escribe `true` al marcar y
 * `null` al desmarcar, así que "no marcado" tiene un solo valor y deshacer la marca devuelve la fila a
 * como estaba. El escritorio **la ignora**: su sync manda solo las columnas que cambiaron y el API
 * actualiza por clave con esas (`CambiosEscritorio::guardarGenerico` + `TablasLegado::filtrar`), así que
 * una sincronización de `motivo_cita` nunca la pisa. El móvil espeja `motivo_cita` como espejo de solo
 * lectura y descarta las columnas que su esquema local no tiene (`LocalDatabase.upsertAll` filtra por
 * `PRAGMA table_info`), así que tampoco le afecta; si algún día quiere usar la marca, se anota en
 * `PENDIENTES-POWERBUILDER.md`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('motivo_cita', 'admite_varias_citas_pendientes')) {
            return;
        }

        Schema::table('motivo_cita', function (Blueprint $table) {
            $table->boolean('admite_varias_citas_pendientes')->nullable()->after('precio');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('motivo_cita', 'admite_varias_citas_pendientes')) {
            return;
        }

        Schema::table('motivo_cita', function (Blueprint $table) {
            $table->dropColumn('admite_varias_citas_pendientes');
        });
    }
};
