<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La configuración del **saludo de cumpleaños automático** (WEB-2.9): interruptor, canal y hora.
 *
 * **Por qué en `evolucion` y no en una tabla nueva de AppDDR.** `evolucion` es la fila de configuración
 * por médico: ahí ya viven las dos plantillas de mensaje (`plantilla_cita`, `plantilla_cumple`) desde el
 * Paso 23. El saludo de cumpleaños es la misma decisión que su plantilla —cuándo y por dónde sale el
 * texto que el médico escribió—, así que partirla dejaría dos lugares que hay que leer juntos para
 * saber si el envío va a salir. El costo de escribir acá es el de siempre en las tablas del legado
 * (AGENTS.md raíz: **solo columnas nuevas, nullable**), y el escritorio **no las pisa**: su sync manda
 * únicamente las columnas que cambiaron en la PC (`CambiosEscritorio::guardarGenerico` +
 * `TablasLegado::filtrar`), y estas tres no existen de su lado. Lo que hay que saber está anotado en
 * `PENDIENTES-POWERBUILDER.md`.
 *
 * **Los valores por defecto los da la aplicación, no la base** (`App\Cumpleanos\ConfiguracionDeCumpleanos`),
 * y por eso las tres columnas son nullable y quedan en `null` en todas las filas que ya existen: un
 * `null` en el interruptor significa "no configurado" y se comporta como **apagado** (`false`), así que
 * esta migración **no cambia el comportamiento de ningún consultorio** hasta que alguien entre a la
 * pantalla y lo prenda. Es el mismo criterio que `motivo_cita.admite_varias_citas_pendientes`.
 *
 * - `enviar_cumpleanos` — el interruptor. `null` y `0` significan lo mismo (no enviar); la pantalla
 *   escribe `1` al prender y `null` al apagar, para que "apagado" tenga un solo valor. Es un entero
 *   nullable y no un `boolean` (el porqué está en el `up()`).
 * - `canal_cumpleanos` — `sms`, `whatsapp` o `correo` (`App\Notificaciones\Canal`). `null` usa el canal
 *   por defecto (correo).
 * - `hora_cumpleanos` — `TIME` (`HH:MM:SS`), la hora local del consultorio a la que sale el saludo.
 *   `null` usa la hora por defecto (09:00). Es `time` y no `timestamp` por la trampa 10 de
 *   `MedicAPI/AGENTS.md`: una `timestamp` no nullable se auto-actualiza sola en MySQL/MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('evolucion', 'enviar_cumpleanos')) {
            return;
        }

        Schema::table('evolucion', function (Blueprint $table) {
            // Se anclan a `plantilla_cumple`, que es la columna hermana: las tres son la misma decisión.
            // `enviar_cumpleanos` es un **entero nullable** (1 = encendido, `null` = apagado) y no un
            // `boolean` de Laravel: el `boolean` emite `tinyint(1)` en MySQL y **SQLite lo trata como una
            // columna numérica sin afinidad**, así que un `''` (lo que Laravel escribe para el `false` de
            // un `boolean` casteado) se guardaría como texto y `where('enviar_cumpleanos', true)` lo
            // levantaría como si estuviera encendido. Con un entero, apagado es `null` y nada más.
            $table->unsignedTinyInteger('enviar_cumpleanos')->nullable()->after('plantilla_cumple');
            $table->string('canal_cumpleanos', 20)->nullable()->after('enviar_cumpleanos');
            $table->time('hora_cumpleanos')->nullable()->after('canal_cumpleanos');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('evolucion', 'enviar_cumpleanos')) {
            return;
        }

        Schema::table('evolucion', function (Blueprint $table) {
            $table->dropColumn(['enviar_cumpleanos', 'canal_cumpleanos', 'hora_cumpleanos']);
        });
    }
};
