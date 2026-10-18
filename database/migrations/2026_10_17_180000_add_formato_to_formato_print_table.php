<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las plantillas del formato del eco** (O-17): una columna nueva en `formato_print` que distingue la
 * plantilla de **todo el formato** de un estudio de las plantillas **de texto** de siempre.
 *
 * `formato_print` es una tabla del legado y se sincroniza con el escritorio (que sube la tabla entera:
 * ver la trampa 88). Por eso la columna es **nullable y aditiva** —la regla del proyecto para las tablas
 * que llevan datos reales—: una fila del escritorio no la trae y se queda en `NULL`, que es exactamente
 * «esto es una plantilla de texto, como siempre». Las filas nuevas son las únicas que la usan, y como
 * cuelgan de un `created` de `sync_changes` (`sin_clave_con_altas_del_app`) el reemplazo del escritorio
 * no las borra.
 *
 * El valor es la **clave del estudio** (`ginecologico`, `obstetrico`, `segundo`, `tercero`, `doppler`,
 * `mamario`, `abdominal`, `fetal`), la misma que viaja en `?tipo=` de la sección de estudio. `descripcion`
 * guarda los campos descriptivos en JSON (las opciones, los textos y las áreas: nunca las medidas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formato_print', function (Blueprint $table) {
            $table->string('formato', 30)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('formato_print', function (Blueprint $table) {
            $table->dropColumn('formato');
        });
    }
};
