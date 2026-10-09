<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\SyncChange;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill de `cola.facturada_escritorio` (WEB-2.12): las filas que ya tenían `estado = 1` venían
 * del escritorio y son facturas, no confirmaciones; las que el app confirmó de verdad se respetan.
 *
 * El `up()` de la migración se vuelve a correr a propósito: la columna ya existe (la creó el
 * bootstrap de los tests), así que lo único que hace es el backfill, que es lo que se prueba acá.
 */
class FacturadaEscritorioTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRACION = '2026_10_08_230000_add_facturada_escritorio_to_cola.php';

    public function test_migracion_marca_las_facturas_viejas_y_respeta_lo_confirmado_en_el_app(): void
    {
        $factura = $this->citaConEstadoUno();
        $confirmada = $this->citaConEstadoUno();
        SyncChange::create([
            'reg_medico' => 'fac-t-001', 'table_name' => 'cola', 'record_id' => $confirmada,
            'operation' => 'updated', 'column_name' => 'estado', 'value' => Cola::ESTADO_CONFIRMADA,
            'occurred_at' => now(), 'source' => 'mobile',
        ]);

        (require database_path('migrations/' . self::MIGRACION))->up();

        $this->assertTrue((bool) DB::table('cola')->where('id', $factura)->value('facturada_escritorio'));
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) DB::table('cola')->where('id', $factura)->value('estado'));

        $this->assertNull(DB::table('cola')->where('id', $confirmada)->value('facturada_escritorio'));
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) DB::table('cola')->where('id', $confirmada)->value('estado'),
            'una confirmación del app no se convierte en factura por tener estado = 1');
    }

    private function citaConEstadoUno(): int
    {
        return DB::table('cola')->insertGetId([
            'reg_medico' => 'fac-t-001', 'fecha' => '2026-09-01', 'hora_ini' => '08:00:00',
            'estado' => Cola::ESTADO_CONFIRMADA, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
