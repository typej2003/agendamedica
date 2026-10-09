<?php

namespace Tests\Feature;

use App\Models\SyncChange;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill de `cola.movida_escritorio` (WEB-2.13): las filas que ya venían con `atendido = 2` —citas
 * que el escritorio postergó— se normalizan al booleano de AppDDR y conservan la movida aparte; el
 * `3` (*Realizada*, declarado y sin escritor) pasa a atendida; lo que el app escribió se respeta.
 *
 * El `up()` de la migración se vuelve a correr a propósito: la columna ya existe (la creó el
 * bootstrap de los tests), así que lo único que hace es el backfill, que es lo que se prueba acá.
 */
class MovidaEscritorioTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRACION = '2026_10_09_100000_add_movida_escritorio_to_cola.php';

    public function test_migracion_normaliza_la_cita_movida_y_la_realizada(): void
    {
        $movida = $this->citaConAtendido(2);
        $realizada = $this->citaConAtendido(3);
        $pendiente = $this->citaConAtendido(0);
        $atendida = $this->citaConAtendido(1);

        (require database_path('migrations/' . self::MIGRACION))->up();

        $this->assertSame(0, (int) DB::table('cola')->where('id', $movida)->value('atendido'),
            'AppDDR no entiende el 2 del escritorio');
        $this->assertTrue((bool) DB::table('cola')->where('id', $movida)->value('movida_escritorio'),
            'la movida se conserva en su columna');

        $this->assertSame(1, (int) DB::table('cola')->where('id', $realizada)->value('atendido'));
        $this->assertNull(DB::table('cola')->where('id', $realizada)->value('movida_escritorio'),
            'realizada no es una cita movida');

        $this->assertSame(0, (int) DB::table('cola')->where('id', $pendiente)->value('atendido'));
        $this->assertSame(1, (int) DB::table('cola')->where('id', $atendida)->value('atendido'));
        $this->assertNull(DB::table('cola')->where('id', $atendida)->value('movida_escritorio'));
    }

    public function test_migracion_respeta_lo_que_escribio_el_app(): void
    {
        $delApp = $this->citaConAtendido(2);
        SyncChange::create([
            'reg_medico' => 'mov-t-001', 'table_name' => 'cola', 'record_id' => $delApp,
            'operation' => 'updated', 'column_name' => 'atendido', 'value' => 1,
            'occurred_at' => now(), 'source' => 'mobile',
        ]);

        (require database_path('migrations/' . self::MIGRACION))->up();

        $this->assertSame(2, (int) DB::table('cola')->where('id', $delApp)->value('atendido'),
            'una edición del app no se pisa con el backfill');
        $this->assertNull(DB::table('cola')->where('id', $delApp)->value('movida_escritorio'));
    }

    private function citaConAtendido(int $atendido): int
    {
        return DB::table('cola')->insertGetId([
            'reg_medico' => 'mov-t-001', 'fecha' => '2026-09-01', 'hora_ini' => '08:00:00',
            'atendido' => $atendido, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
