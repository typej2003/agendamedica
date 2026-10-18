<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Crecimiento\MedidasAntropometricas;
use App\Clinica\Crecimiento\PercentilesDeCrecimiento;
use App\Clinica\Crecimiento\PosicionEnBandas;
use PHPUnit\Framework\TestCase;

/**
 * El servicio de percentiles y las fórmulas de las mediciones (WEB-7.4.3a/b). La referencia es **inventada para la prueba** (números
 * redondos, tres rangos de edad): ninguna fila sale de los datos reales del escritorio.
 */
class PercentilesDeCrecimientoTest extends TestCase
{
    private function fila(int $ini, int $fin, float $anos, array $extra = []): array
    {
        return array_merge([
            'edad_ini_dia' => $ini, 'edad_fin_dia' => $fin, 'anos' => $anos,
            'peso_b' => 10.0, 'peso_m' => 12.0, 'peso_a' => 14.0,
            'talla_b' => 70.0, 'talla_m' => 75.0, 'talla_a' => 80.0,
            'cefalica_b' => null, 'cefalica_m' => null, 'cefalica_a' => null,
            'brazo_b' => 13.0, 'brazo_m' => 14.0, 'brazo_a' => 15.0,
            'imc_b' => 15.0, 'imc_m' => 16.0, 'imc_a' => 18.0,
        ], $extra);
    }

    private function servicio(): PercentilesDeCrecimiento
    {
        $nino = [
            $this->fila(0, 90, 0.0, ['peso_b' => 3.0, 'peso_m' => 3.5, 'peso_a' => 4.0]),
            $this->fila(91, 365, 0.25),
            // Hueco entre el día 366 y el 400: la edad 380 usa el rango anterior (el de 91–365).
            $this->fila(400, 730, 1.0, ['peso_b' => 20.0, 'peso_m' => 22.0, 'peso_a' => 24.0]),
        ];
        $nina = [$this->fila(0, 730, 0.0, ['peso_b' => 1.0, 'peso_m' => 2.0, 'peso_a' => 3.0])];
        $presion = [
            ['sexo' => 'M', 'edad_ini_dia' => 0, 'edad_fin_dia' => 365, 'anos' => 0.0, 'tres' => 60, 'diez' => 65, 'veinte_5' => 70,
                'cincuenta' => 80, 'setenta_5' => 90, 'noventa' => 100, 'noventa_7' => 110],
        ];

        return new PercentilesDeCrecimiento($nino, $nina, ['sistolica' => $presion]);
    }

    public function test_la_edad_se_cuenta_en_dias_y_una_fecha_anterior_al_nacimiento_no_tiene_edad(): void
    {
        $this->assertSame(0, PercentilesDeCrecimiento::diasDeEdad('2024-01-01', '2024-01-01'));
        $this->assertSame(366, PercentilesDeCrecimiento::diasDeEdad('2024-01-01', '2025-01-01'));
        $this->assertNull(PercentilesDeCrecimiento::diasDeEdad('2024-01-02', '2024-01-01'));
        $this->assertNull(PercentilesDeCrecimiento::diasDeEdad(null, '2024-01-01'));
        $this->assertNull(PercentilesDeCrecimiento::diasDeEdad('2024-01-01', null));
    }

    public function test_el_sexo_elige_la_tabla_y_otro_valor_no_tiene_referencia(): void
    {
        $this->assertSame('nino', PercentilesDeCrecimiento::sexoDeReferencia('m'));
        $this->assertSame('nina', PercentilesDeCrecimiento::sexoDeReferencia(' F '));
        $this->assertNull(PercentilesDeCrecimiento::sexoDeReferencia(null));
        $this->assertNull(PercentilesDeCrecimiento::sexoDeReferencia('X'));
        $this->assertNull($this->servicio()->banda('peso', 12, 'X', 100));
    }

    public function test_la_banda_dice_la_curva_mas_cercana_y_el_tramo(): void
    {
        $servicio = $this->servicio();
        // Edad 100 días → rango 91–365 (peso 10 / 12 / 14).
        $casos = [
            [9.0, 'b', PosicionEnBandas::DEBAJO],
            [10.0, 'b', PosicionEnBandas::BAJO_MEDIO],
            [10.9, 'b', PosicionEnBandas::BAJO_MEDIO],
            [11.2, 'm', PosicionEnBandas::BAJO_MEDIO],
            [12.0, 'm', PosicionEnBandas::MEDIO_ALTO],
            [13.5, 'a', PosicionEnBandas::MEDIO_ALTO],
            [14.0, 'a', PosicionEnBandas::MEDIO_ALTO],
            [15.0, 'a', PosicionEnBandas::ENCIMA],
        ];

        foreach ($casos as [$valor, $banda, $zona]) {
            $posicion = $servicio->banda('peso', $valor, 'M', 100);
            $this->assertNotNull($posicion, "valor $valor");
            $this->assertSame($banda, $posicion->banda, "banda de $valor");
            $this->assertSame($zona, $posicion->zona, "zona de $valor");
        }

        $this->assertSame(['b' => 10.0, 'm' => 12.0, 'a' => 14.0], $servicio->banda('peso', 12, 'M', 100)->curvas);
        $this->assertSame('Por encima de la curva alta', $servicio->banda('peso', 99, 'M', 100)->texto());
        $this->assertTrue($servicio->banda('peso', 12, 'M', 100)->enRango());
        $this->assertFalse($servicio->banda('peso', 9, 'M', 100)->enRango());
    }

    public function test_cada_sexo_usa_su_tabla(): void
    {
        $servicio = $this->servicio();

        $this->assertSame('m', $servicio->banda('peso', 2.0, 'F', 10)->banda);
        $this->assertSame(PosicionEnBandas::DEBAJO, $servicio->banda('peso', 2.0, 'M', 10)->zona);
    }

    public function test_el_rango_se_busca_por_dias_con_huecos_y_sin_extrapolar(): void
    {
        $servicio = $this->servicio();

        $this->assertSame(0.0, $servicio->filaDe('nino', 0)['anos']);
        $this->assertSame(0.0, $servicio->filaDe('nino', 90)['anos']);
        $this->assertSame(0.25, $servicio->filaDe('nino', 91)['anos']);
        // En el hueco 366–399 vale el rango anterior.
        $this->assertSame(0.25, $servicio->filaDe('nino', 380)['anos']);
        $this->assertSame(1.0, $servicio->filaDe('nino', 400)['anos']);
        $this->assertSame(1.0, $servicio->filaDe('nino', 730)['anos']);
        // Pasado el último rango no hay referencia.
        $this->assertNull($servicio->filaDe('nino', 731));
        $this->assertNull($servicio->banda('peso', 22, 'M', 5000));
        $this->assertNull($servicio->filaDe('nino', -1));
    }

    public function test_una_medida_sin_curvas_completas_o_sin_valor_no_tiene_posicion(): void
    {
        $servicio = $this->servicio();

        // El perímetro cefálico está vacío en la referencia de la prueba.
        $this->assertNull($servicio->banda('cefalica', 40, 'M', 100));
        $this->assertNull($servicio->banda('peso', null, 'M', 100));
        $this->assertNull($servicio->banda('peso', 0, 'M', 100));
        $this->assertNull($servicio->banda('peso', 12, 'M', null));
        $this->assertNull($servicio->banda('presion', 12, 'M', 100));
        $this->assertNotNull($servicio->banda('imc', 16, 'M', 100));
        $this->assertNotNull($servicio->banda('brazo', 14, 'F', 100));
    }

    public function test_sin_ninguna_referencia_todo_es_null_y_no_falla(): void
    {
        $vacio = new PercentilesDeCrecimiento([], []);

        $this->assertFalse($vacio->hayReferencia('M'));
        $this->assertNull($vacio->banda('peso', 12, 'M', 100));
        $this->assertSame([], $vacio->puntosDeBandas('peso', 'M'));
        $this->assertSame(['b' => [], 'm' => [], 'a' => []], $vacio->curvasDePesoPorTalla('M'));
        $this->assertFalse($vacio->hayReferenciaDe('sistolica'));
        $this->assertNull($vacio->percentil('sistolica', 80, 'M', 10));
        $this->assertTrue($this->servicio()->hayReferencia('M'));
    }

    public function test_los_puntos_para_dibujar_van_ordenados_y_se_recortan_por_juego(): void
    {
        $servicio = $this->servicio();

        $todos = $servicio->puntosDeBandas('peso', 'M');
        $this->assertSame([0.0, 0.25, 1.0], array_column($todos, 'anos'));
        $this->assertSame(['anos' => 0.25, 'dias' => 91, 'b' => 10.0, 'm' => 12.0, 'a' => 14.0], $todos[1]);

        $this->assertSame([0.0, 0.25], array_column($servicio->puntosDeBandas('peso', 'M', 0, 0.5), 'anos'));
        $this->assertSame([1.0], array_column($servicio->puntosDeBandas('peso', 'M', 1, 5), 'anos'));
    }

    public function test_el_peso_por_talla_une_la_talla_y_el_peso_de_cada_curva(): void
    {
        $curvas = $this->servicio()->curvasDePesoPorTalla('F');

        $this->assertSame([['talla' => 70.0, 'peso' => 1.0]], $curvas['b']);
        $this->assertSame([['talla' => 75.0, 'peso' => 2.0]], $curvas['m']);
        $this->assertSame([['talla' => 80.0, 'peso' => 3.0]], $curvas['a']);
    }

    public function test_la_presion_dice_entre_que_percentiles_cae(): void
    {
        $servicio = $this->servicio();

        $this->assertSame('Menor al P3', $servicio->percentil('sistolica', 50, 'M', 10)->texto());
        $this->assertSame('Entre el P25 y el P50', $servicio->percentil('sistolica', 75, 'M', 10)->texto());
        $this->assertSame('Entre el P50 y el P75', $servicio->percentil('sistolica', 80, 'M', 10)->texto());
        $this->assertSame('Mayor al P97', $servicio->percentil('sistolica', 120, 'M', 10)->texto());
        // Sin tabla de pulso, o sin edad, no hay posición.
        $this->assertNull($servicio->percentil('pulso', 100, 'M', 10));
        $this->assertNull($servicio->percentil('sistolica', 80, 'M', null));
        $this->assertTrue($servicio->hayReferenciaDe('sistolica'));
        // Una sola tabla sin ese sexo: se usa igual (la tabla no siempre declara el sexo).
        $this->assertNotNull($servicio->percentil('sistolica', 80, 'F', 10));
    }

    public function test_el_imc_y_el_ac_siguen_las_formulas_del_escritorio(): void
    {
        // gffIMC: peso / talla² (talla en metros), con 2 decimales.
        $this->assertSame(22.04, MedidasAntropometricas::imc(70, 178.2));
        $this->assertSame(16.0, MedidasAntropometricas::imc(16, 100));
        $this->assertSame(0.0, MedidasAntropometricas::imc(0, 100));
        $this->assertSame(0.0, MedidasAntropometricas::imc(10, 0));
        $this->assertSame(0.0, MedidasAntropometricas::imc(null, 100));

        // gffAC: talla_cm^0,725 · peso^0,425 · 71,84 / 10000.
        $this->assertSame(round((170 ** 0.725) * (70 ** 0.425) * 71.84 / 10000, 2), MedidasAntropometricas::ac(70, 170));
        $this->assertSame(1.81, MedidasAntropometricas::ac(70, 170));
        $this->assertSame(0.0, MedidasAntropometricas::ac(70, 0));

        // Un recién nacido (< 0,6) usa la fórmula en gramos.
        $gramos = 3.2 * 1000;
        $esperado = round((50 ** 0.3) * ($gramos ** (0.7285 - 0.0188 * log($gramos))) * 3.207 / 10000, 2);
        $this->assertSame($esperado, MedidasAntropometricas::ac(3.2, 50));
        $this->assertLessThan(0.6, MedidasAntropometricas::ac(3.2, 50));
    }
}
