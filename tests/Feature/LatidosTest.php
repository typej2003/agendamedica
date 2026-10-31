<?php

namespace Tests\Feature;

use App\Latidos\Conversion;
use App\Latidos\Latidos;
use App\Models\ConfiguracionLatidos;
use App\Models\Latido;
use App\Models\Medico;
use App\Models\MovimientoDeLatidos;
use App\Models\Pago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * **El motor de Latidos** (fase 1, 2026-10-31): libro, caché, retenciones, idempotencia y ajustes.
 *
 * Los Latidos no son dinero: 100 Latidos = USD 1 de descuento y nunca se retiran. Lo que se prueba acá es
 * que el libro y la caché no se desincronicen, que dos pestañas no usen lo mismo y que un webhook repetido
 * no acredite ni descuente dos veces.
 */
class LatidosTest extends TestCase
{
    use DatabaseTransactions;

    private const REG = 'MPPS-LAT-1';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicio(): Latidos
    {
        return app(Latidos::class);
    }

    private function usuario(): User
    {
        return User::create([
            'name' => 'Root Latidos',
            'email' => 'lat-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
    }

    /** Un pago mínimo, pendiente, para probar retenciones. */
    private function pago(string $reg = self::REG, int $montoE2 = 2000, ?Carbon $expira = null): Pago
    {
        return Pago::create([
            'reg_medico' => $reg,
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'concepto' => 'Plan de prueba',
            'moneda' => 'USD',
            'monto_e2' => $montoE2,
            'monto_usd_e2' => $montoE2,
            'estado' => Pago::PENDIENTE,
            'expira_el' => $expira ?? now()->addMinutes(15),
        ]);
    }

    public function test_la_conversion_es_100_latidos_por_dolar(): void
    {
        $this->assertSame(2000, Conversion::desdeUsdE2(2000));
        $this->assertSame(2000, Conversion::aUsdE2(2000));
        $this->assertSame(500, Conversion::desdeUsdE2(500));
        $this->assertSame(500, Conversion::aUsdE2(500));
        $this->assertSame('2.000 Latidos', Conversion::formatear(2000));
    }

    public function test_acredita_y_descuenta_los_latidos(): void
    {
        $servicio = $this->servicio();

        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:1');
        $this->assertSame(2000, $servicio->disponibles(self::REG));
        $this->assertSame(2000, $servicio->disponiblesParaUsar(self::REG));

        $servicio->descontar(self::REG, 500, MovimientoDeLatidos::AJUSTE, 'ajuste:prueba');

        $this->assertSame(1500, $servicio->disponibles(self::REG));
        $this->assertSame(1500, $servicio->disponiblesParaUsar(self::REG));

        $movimiento = MovimientoDeLatidos::where('clave_idempotencia', 'ajuste:prueba')->firstOrFail();
        $this->assertSame(-500, (int) $movimiento->latidos);
        $this->assertSame(2000, (int) $movimiento->disponibles_antes);
        $this->assertSame(1500, (int) $movimiento->disponibles_despues);
    }

    public function test_no_deja_los_latidos_en_negativo(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 100, MovimientoDeLatidos::PROMOCION, 'promo:negativo');

        try {
            $servicio->descontar(self::REG, 200, MovimientoDeLatidos::AJUSTE, 'ajuste:negativo');
            $this->fail('Se esperaba un error por Latidos insuficientes.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No hay Latidos suficientes', $e->getMessage());
        }

        $this->assertSame(100, $servicio->disponibles(self::REG));
        $this->assertSame(1, MovimientoDeLatidos::where('clave_idempotencia', 'promo:negativo')->count());
    }

    public function test_la_retencion_reserva_sin_descontar(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:retencion');

        $pago = $this->pago();
        $retencion = $servicio->retener($pago, 500);

        $this->assertSame('activa', $retencion->estado);
        $this->assertSame(2000, $servicio->disponibles(self::REG));
        $this->assertSame(1500, $servicio->disponiblesParaUsar(self::REG));

        $otro = $this->pago(montoE2: 1600);
        try {
            $servicio->retener($otro, 1600);
            $this->fail('Se esperaba un error: lo retenido no deja usar 1.600.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No hay Latidos suficientes', $e->getMessage());
        }
    }

    public function test_consumir_descuenta_una_sola_vez(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:consumir');

        $pago = $this->pago();
        $servicio->retener($pago, 500);
        $servicio->consumir($pago);

        $this->assertSame(1500, $servicio->disponibles(self::REG));

        $servicio->consumir($pago);

        $this->assertSame(1500, $servicio->disponibles(self::REG));
        $this->assertSame(2, MovimientoDeLatidos::where('latido_id', Latido::where('reg_medico', self::REG)->value('id'))->count());
    }

    public function test_liberar_devuelve_los_latidos_a_disponibles(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:liberar');

        $pago = $this->pago();
        $servicio->retener($pago, 500);
        $this->assertSame(1500, $servicio->disponiblesParaUsar(self::REG));

        $servicio->liberar($pago);

        $this->assertSame(2000, $servicio->disponibles(self::REG));
        $this->assertSame(2000, $servicio->disponiblesParaUsar(self::REG));
    }

    public function test_la_clave_de_idempotencia_no_duplica_el_credito(): void
    {
        $servicio = $this->servicio();

        $primero = $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::COMPRA, 'compra:99');
        $segundo = $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::COMPRA, 'compra:99');

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(2000, $servicio->disponibles(self::REG));
        $this->assertSame(1, MovimientoDeLatidos::where('clave_idempotencia', 'compra:99')->count());
    }

    public function test_retener_el_mismo_pago_dos_veces_no_duplica(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:mismo-pago');

        $pago = $this->pago();
        $primera = $servicio->retener($pago, 500);
        $segunda = $servicio->retener($pago, 500);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1500, $servicio->disponiblesParaUsar(self::REG));
    }

    public function test_no_se_puede_retener_el_mismo_pago_con_otro_monto(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 2000, MovimientoDeLatidos::PROMOCION, 'promo:otro-monto');

        $pago = $this->pago();
        $servicio->retener($pago, 500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otro monto');

        $servicio->retener($pago, 600);
    }

    public function test_el_ajuste_manual_suma_resta_y_queda_auditado(): void
    {
        $servicio = $this->servicio();
        $autor = $this->usuario();

        $servicio->acreditar(self::REG, 1000, MovimientoDeLatidos::PROMOCION, 'promo:ajuste');

        $servicio->ajustar(self::REG, 200, 'Cortesía de soporte', $autor);
        $this->assertSame(1200, $servicio->disponibles(self::REG));

        $servicio->ajustar(self::REG, -300, 'Corrección de una carga', $autor);
        $this->assertSame(900, $servicio->disponibles(self::REG));

        $movimiento = MovimientoDeLatidos::where('tipo', MovimientoDeLatidos::AJUSTE)->latest('id')->firstOrFail();
        $this->assertSame($autor->id, (int) $movimiento->autor_id);
        $this->assertSame(-300, (int) $movimiento->latidos);
        $this->assertSame('Corrección de una carga', $movimiento->nota);
    }

    public function test_el_ajuste_negativo_no_deja_bajo_cero_sin_permiso(): void
    {
        $servicio = $this->servicio();
        $autor = $this->usuario();
        $servicio->acreditar(self::REG, 100, MovimientoDeLatidos::PROMOCION, 'promo:ajuste-negativo');

        try {
            $servicio->ajustar(self::REG, -150, 'Error de carga', $autor);
            $this->fail('Se esperaba un error por dejar los Latidos en negativo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('negativo', $e->getMessage());
        }

        $servicio->ajustar(self::REG, -150, 'Error de carga confirmado', $autor, true);
        $this->assertSame(-50, $servicio->disponibles(self::REG));
    }

    public function test_verificar_detecta_un_descuadre_de_la_cache(): void
    {
        $servicio = $this->servicio();
        $servicio->acreditar(self::REG, 1000, MovimientoDeLatidos::PROMOCION, 'promo:verificar');

        Latido::where('reg_medico', self::REG)->update(['disponibles' => 999]);

        $resultado = $servicio->verificar(self::REG);

        $this->assertFalse($resultado['ok']);
        $this->assertSame(1000, $resultado['suma']);
        $this->assertSame(999, $resultado['disponibles']);
        $this->assertNotEmpty($resultado['diferencias']);
    }

    public function test_el_comando_crea_las_filas_y_es_idempotente(): void
    {
        Medico::create(['name' => 'Uno', 'lastname' => 'Latidos', 'reg_medico' => 'MPPS-CMD-1', 'es_centro' => false]);
        Medico::create(['name' => 'Dos', 'lastname' => 'Latidos', 'reg_medico' => 'MPPS-CMD-2', 'es_centro' => false]);

        $this->artisan('latidos:crear')->assertExitCode(0);
        $this->assertSame(2, Latido::whereIn('reg_medico', ['MPPS-CMD-1', 'MPPS-CMD-2'])->count());

        $this->artisan('latidos:crear')->assertExitCode(0);
        $this->assertSame(2, Latido::whereIn('reg_medico', ['MPPS-CMD-1', 'MPPS-CMD-2'])->count());
    }

    public function test_la_configuracion_nace_con_la_compra_apagada(): void
    {
        $configuracion = ConfiguracionLatidos::actual();

        $this->assertFalse($configuracion->compraActiva());
        $this->assertTrue($configuracion->referidosActivos());
        $this->assertSame(2000, $configuracion->latidos_referente);
        $this->assertSame(2000, $configuracion->latidos_referido);
    }
}
