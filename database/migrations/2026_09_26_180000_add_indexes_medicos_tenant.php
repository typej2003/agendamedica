<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restricciones e índices que faltaban en las tablas que resuelven "qué médico es cuál dentro de
 * un tenant" (Paso 22.B/22.C del ROADMAP) — sin esto, una fila duplicada o mal cargada podía
 * volver ambigua la resolución de `cola.medico` sin que nada lo impidiera a nivel de base de
 * datos, el mismo tipo de confusión que costó todo el Paso 22 desenredar.
 *
 * - `evolucion`: **única** por `(reg_medico, clave)` — es justamente la garantía que `cola.medico`
 *   necesita para no ser ambiguo (dos médicos del mismo tenant no pueden compartir `clave`).
 *   `evolucion` es tabla del legado (sí sincroniza datos reales, ver Docs/Wiki/07-modelo-de-datos.md):
 *   si el dump de producción ya tuviera duplicados sin detectar, esta migración fallaría al
 *   aplicarse ahí — revisar antes con una consulta de duplicados si eso llega a pasar. Contra los
 *   datos de prueba locales (verificado) no hay ninguno. También un índice en `correo_med`, la
 *   columna por la que se cruza con `medicos.email` en `SyncAppDataController`.
 * - `medico_registros`: **única** por `(medico_id, reg_medico)` — evita registrar dos veces al
 *   mismo médico en el mismo tenant. Tabla de AppDDR (no viene del legado), se puede reestructurar
 *   libremente.
 * - `medicos.email`: índice simple (no único) — se consulta seguido (`medicosDelTenant`,
 *   `claveDelMedico`, login), pero no se fuerza unicidad porque no está verificado que los datos
 *   migrados del legado no tengan correos repetidos o vacíos.
 * - `offices.reg_medico`: índice simple — desde el Paso 22.C se consulta por tenant, no solo por
 *   `medico_id` (que ya tiene su FK). Tabla de AppDDR.
 */
class AddIndexesMedicosTenant extends Migration
{
    public function up()
    {
        Schema::table('evolucion', function (Blueprint $table) {
            $table->unique(['reg_medico', 'clave'], 'evolucion_reg_medico_clave_unique');
            $table->index('correo_med', 'evolucion_correo_med_index');
        });

        Schema::table('medico_registros', function (Blueprint $table) {
            $table->unique(['medico_id', 'reg_medico'], 'medico_registros_medico_reg_medico_unique');
        });

        Schema::table('medicos', function (Blueprint $table) {
            $table->index('email', 'medicos_email_index');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->index('reg_medico', 'offices_reg_medico_index');
        });
    }

    public function down()
    {
        Schema::table('evolucion', function (Blueprint $table) {
            $table->dropUnique('evolucion_reg_medico_clave_unique');
            $table->dropIndex('evolucion_correo_med_index');
        });

        Schema::table('medico_registros', function (Blueprint $table) {
            $table->dropUnique('medico_registros_medico_reg_medico_unique');
        });

        Schema::table('medicos', function (Blueprint $table) {
            $table->dropIndex('medicos_email_index');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropIndex('offices_reg_medico_index');
        });
    }
}
