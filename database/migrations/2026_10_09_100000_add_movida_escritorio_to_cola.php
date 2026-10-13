<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.movida_escritorio` — el escritorio PowerBuilder **postergó** esta cita a otro día (WEB-2.13;
 * decisión 8 de PLAN-WEB.md §3 y contraste de agenda §4).
 *
 * El escritorio usa `cola.atendido` con cuatro valores —`0` pendiente · `1` atendido · `2` **movida** ·
 * `3` *Realizada* (declarada y sin escritor)— y sus DataWindows de agenda **excluyen `atendido = 2`**
 * (`w_calendar.srw:300-303` y `w_pacientes_a_atender_hoy.srw:395-398` lo escriben al cambiar la cita de
 * día). AppDDR usa la misma columna como **booleano** (0 pendiente / 1 atendido) y no filtra por `2`:
 * una cita movida se pintaba como pendiente, con los botones habilitados, mientras el mismo paciente ya
 * estaba en su fecha nueva.
 *
 * Como ninguna superficie adopta la convención de la otra, el `2` del escritorio no puede vivir en
 * `atendido`: se guarda acá, y la **capa de sincronización** es la única que traduce
 * (`App\Sync\Escritorio\AtencionCita`).
 *
 * Nullable (regla del proyecto para tablas del legado, PLAN-WEB.md R4): `null`/`false` = cita normal;
 * `true` = el escritorio la movió. El app y el móvil no la escriben.
 *
 * A diferencia de `facturada_escritorio`, esta marca es **bidireccional**: `w_nueva_cita_7` **resetea**
 * `atendido` a 0 al reagendar en el mismo día, así que un `0` del escritorio sí des-marca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cola', 'movida_escritorio')) {
            Schema::table('cola', function (Blueprint $table) {
                $table->boolean('movida_escritorio')->nullable()->after('atendido');
            });
        }

        $this->normalizarAtendido();
    }

    public function down(): void
    {
        if (Schema::hasColumn('cola', 'movida_escritorio')) {
            Schema::table('cola', function (Blueprint $table) {
                $table->dropColumn('movida_escritorio');
            });
        }
    }

    /**
     * Las filas que ya venían con `atendido = 2/3` —las cargó la carga inicial antes de que existiera
     * la traducción— se normalizan: el `2` pasa a `movida_escritorio`, el `3` (*Realizada*) a
     * `atendido = 1`, y en los dos casos `atendido` queda en el dominio booleano de AppDDR.
     *
     * La excepción son las filas donde el app editó `atendido` (anotado en `sync_changes` con
     * `source = mobile`): AppDDR solo escribe 0/1, así que esas no se tocan.
     */
    private function normalizarAtendido(): void
    {
        if (! Schema::hasTable('sync_changes')) {
            return;
        }

        $sinEdicionDelApp = function ($q) {
            $q->select(DB::raw(1))
                ->from('sync_changes')
                ->whereColumn('sync_changes.record_id', 'cola.id')
                ->where('sync_changes.table_name', 'cola')
                ->where('sync_changes.column_name', 'atendido')
                ->where('sync_changes.source', 'mobile');
        };

        DB::table('cola')
            ->where('atendido', 2)
            ->whereNotExists($sinEdicionDelApp)
            ->update(['movida_escritorio' => true, 'atendido' => 0]);

        DB::table('cola')
            ->where('atendido', 3)
            ->whereNotExists($sinEdicionDelApp)
            ->update(['atendido' => 1]);
    }
};
