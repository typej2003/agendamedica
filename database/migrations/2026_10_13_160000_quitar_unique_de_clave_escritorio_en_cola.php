<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.clave_escritorio` deja de ser única: el legado permite dos citas a la misma fecha y hora.
 *
 * La clave del escritorio de una cita es `fecha|hora_ini` (`TablasLegado::claveCola()`), pero eso es
 * una **suposición** que no es cierta: la clave primaria de `cola` en el legado es
 * `("fecha", "numhistoria")` —así está en los ocho esquemas de `Docs/MICONSULTAGI-legado/esquemas-asa/`
 * (pediatría: `pediatria.sql` línea 242)—, o sea que dos pacientes distintos a la misma hora son dos
 * filas legítimas. Reventó con datos reales de pediatría: la carga inicial moría con
 * `SQLSTATE[23000]: Duplicate entry 'pedia_0001-2011-05-20|23:59:59'` y el lote entero se reintentaba en
 * bucle. AppDDR también admite dos citas pendientes a la misma hora
 * (`motivo_cita.admite_varias_citas_pendientes`, WEB-2.17).
 *
 * Solo se cambia el índice único por uno **común**, que sirve igual para las búsquedas por
 * `(reg_medico, clave_escritorio)`. `historias` y `consultas` **quedan como están**: sus claves sí son
 * únicas de verdad (el número de historia, y la historia + el número de consulta). Ver la trampa 109.
 */
return new class extends Migration
{
    private const INDICE_UNICO = 'cola_reg_medico_clave_escritorio_unique';
    private const INDICE = 'cola_reg_medico_clave_escritorio_index';

    public function up(): void
    {
        if (! Schema::hasTable('cola') || ! Schema::hasColumn('cola', 'clave_escritorio')) {
            return;
        }

        // Se detecta sola: en un servidor donde alguien ya quitó el UNIQUE a mano para destrabar la
        // carga (es el incendio que esta migración apaga), volver a intentarlo fallaría.
        if ($this->existeIndice(self::INDICE_UNICO)) {
            Schema::table('cola', function (Blueprint $table) {
                $table->dropUnique(self::INDICE_UNICO);
            });
        }
        if (! $this->existeIndice(self::INDICE)) {
            Schema::table('cola', function (Blueprint $table) {
                $table->index(['reg_medico', 'clave_escritorio'], self::INDICE);
            });
        }
    }

    /**
     * ¿Existe el índice? SQLite y MySQL guardan su catálogo en lugares distintos; el nombre del
     * índice común y el del único son los que nombra Laravel, así que sirve para los dos.
     */
    private function existeIndice(string $indice): bool
    {
        $conexion = Schema::getConnection();

        if ($conexion->getDriverName() === 'sqlite') {
            return (bool) $conexion->selectOne(
                "select 1 as hay from sqlite_master where type = 'index' and name = ? limit 1",
                [$indice]
            );
        }

        return (bool) $conexion->selectOne(
            'select 1 as hay from information_schema.statistics
             where table_schema = database() and table_name = ? and index_name = ? limit 1',
            ['cola', $indice]
        );
    }

    /**
     * No se vuelve a poner el UNIQUE: con los duplicados que ya entraron (dos citas a la misma hora, que
     * son legítimas) la migración fallaría. El índice común se deja. Ver la trampa 109.
     */
    public function down(): void
    {
    }
};
