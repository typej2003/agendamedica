<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.facturada_escritorio` — el escritorio PowerBuilder ya le elaboró la factura a esta cita
 * (WEB-2.12; decisión 8 de PLAN-WEB.md §3 y contraste de agenda §3).
 *
 * El escritorio usa `cola.estado = 1` para "factura elaborada": lo escribe facturación al cerrar la
 * factura (`w_factura_principal.srw:889-894`) y `w_pacientes_atendidos_hoy.srw:64-69` se niega a
 * facturar de nuevo cuando lo encuentra. La agenda del escritorio escribe siempre `estado = 0`, y la
 * eliminación de una factura **no** lo vuelve a 0 (`w_factura_principal_eliminar.srw:1109-1115`):
 * la marca es de un solo sentido.
 *
 * AppDDR usa la misma columna para otra cosa — la **confirmación** (0 no confirmada, 1 consultorio,
 * 2 paciente, ver `App\Models\Cola`). Como ninguna superficie adopta la convención de la otra, el
 * dato del escritorio no puede vivir en `estado`: se guarda acá, y la **capa de sincronización** es
 * la única que traduce (`App\Sync\Escritorio\EstadoCita`).
 *
 * Nullable (regla del proyecto para tablas del legado, PLAN-WEB.md R4): `null`/`false` = sin factura
 * del escritorio; `true` = facturada. El app y el móvil no la escriben.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cola', 'facturada_escritorio')) {
            Schema::table('cola', function (Blueprint $table) {
                $table->boolean('facturada_escritorio')->nullable()->after('estado');
            });
        }

        $this->marcarFacturasViejas();
    }

    public function down(): void
    {
        if (Schema::hasColumn('cola', 'facturada_escritorio')) {
            Schema::table('cola', function (Blueprint $table) {
                $table->dropColumn('facturada_escritorio');
            });
        }
    }

    /**
     * Las filas que ya tenían `estado = 1` venían del escritorio: son facturas, no confirmaciones.
     * Se pasan a `facturada_escritorio` y se les limpia `estado` para que la web y el móvil dejen de
     * pintarlas como "Confirmada".
     *
     * La excepción son las filas donde el app editó `estado` (anotado en `sync_changes` con
     * `source = mobile`): esas son confirmaciones de AppDDR de verdad y no se tocan. Sin ese filtro,
     * la migración borraría confirmaciones reales de un consultorio que ya usa la web o el móvil.
     */
    private function marcarFacturasViejas(): void
    {
        if (! Schema::hasTable('sync_changes')) {
            return;
        }

        DB::table('cola')
            ->where('estado', 1)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sync_changes')
                    ->whereColumn('sync_changes.record_id', 'cola.id')
                    ->where('sync_changes.table_name', 'cola')
                    ->where('sync_changes.column_name', 'estado')
                    ->where('sync_changes.source', 'mobile');
            })
            ->update(['facturada_escritorio' => true, 'estado' => 0]);
    }
};
