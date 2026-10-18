<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Crecimiento\EdadEnTexto;
use App\Clinica\Crecimiento\EjesDeLaGrafica;
use App\Clinica\Crecimiento\GraficaDeCrecimiento;
use PHPUnit\Framework\TestCase;

/** La geometría de las gráficas de crecimiento y sus ejes (WEB-7.4.3c). Números inventados para la prueba. */
class GraficaDeCrecimientoTest extends TestCase
{
    public function test_las_marcas_son_redondas_y_contienen_los_datos(): void
    {
        $ejes = EjesDeLaGrafica::marcas(3.2, 17.8);

        $this->assertLessThanOrEqual(3.2, $ejes['min']);
        $this->assertGreaterThanOrEqual(17.8, $ejes['max']);
        $this->assertSame($ejes['min'], $ejes['marcas'][0]);
        $this->assertSame($ejes['max'], end($ejes['marcas']));
        // Cada paso es 1, 2 o 5 por una potencia de diez, y son entre 4 y 10 marcas.
        $paso = $ejes['marcas'][1] - $ejes['marcas'][0];
        $this->assertContains((int) round($paso / (10 ** floor(log10($paso)))), [1, 2, 5]);
        $this->assertGreaterThanOrEqual(4, count($ejes['marcas']));
        $this->assertLessThanOrEqual(10, count($ejes['marcas']));

        $this->assertSame([0.0, 5.0, 10.0, 15.0, 20.0], array_map('floatval', EjesDeLaGrafica::marcas(0, 20, 4)['marcas']));
    }

    public function test_un_solo_valor_abre_un_rango_a_su_alrededor(): void
    {
        $ejes = EjesDeLaGrafica::marcas(12, 12);

        $this->assertLessThan(12, $ejes['min']);
        $this->assertGreaterThan(12, $ejes['max']);
    }

    public function test_los_puntos_se_ubican_dentro_del_marco_y_la_y_va_invertida(): void
    {
        $grafica = new GraficaDeCrecimiento(
            'Peso', 'Edad (años)', 'Peso (kg)',
            [
                'b' => [['x' => 0.0, 'y' => 2.0], ['x' => 5.0, 'y' => 12.0]],
                'm' => [['x' => 0.0, 'y' => 3.0], ['x' => 5.0, 'y' => 16.0]],
                'a' => [['x' => 0.0, 'y' => 4.0], ['x' => 5.0, 'y' => 20.0]],
            ],
            [['x' => 2.5, 'y' => 12.0, 'etiqueta' => 'p1'], ['x' => 4.0, 'y' => 14.0, 'etiqueta' => 'p2']],
            [0.0, 5.0],
            [0.0, 1.0, 2.0, 3.0, 4.0, 5.0],
        );
        $marco = $grafica->marco();

        $this->assertTrue($grafica->conCurvas());
        $this->assertTrue($grafica->conPuntos());

        foreach ($grafica->puntosDelNino() as $punto) {
            $this->assertGreaterThanOrEqual($marco['x'], $punto['cx']);
            $this->assertLessThanOrEqual($marco['x'] + $marco['ancho'], $punto['cx']);
            $this->assertGreaterThanOrEqual($marco['y'], $punto['cy']);
            $this->assertLessThanOrEqual($marco['y'] + $marco['alto'], $punto['cy']);
        }

        // Un peso mayor queda más arriba (menor y) y una edad mayor más a la derecha.
        [$p1, $p2] = $grafica->puntosDelNino();
        $this->assertLessThan($p1['cy'], $p2['cy']);
        $this->assertGreaterThan($p1['cx'], $p2['cx']);

        // La curva alta va por encima de la baja en el último punto.
        $this->assertLessThan($grafica->finDeLaCurva('b')['y'], $grafica->finDeLaCurva('a')['y']);

        // La franja recorre la alta de ida y la baja de vuelta: el doble de vértices.
        $this->assertCount(4, explode(' ', $grafica->franja()));
        $this->assertCount(2, explode(' ', $grafica->polilinea('m')));
        $this->assertSame(['0', '1', '2', '3', '4', '5'], array_column($grafica->marcasDelEjeX(), 'texto'));
    }

    public function test_sin_curvas_se_dibuja_igual_con_los_puntos_del_nino(): void
    {
        $vacias = ['b' => [], 'm' => [], 'a' => []];
        $grafica = new GraficaDeCrecimiento('Talla', 'Edad', 'cm', $vacias, [['x' => 1.0, 'y' => 75.0, 'etiqueta' => 'a']], [0.0, 5.0], [0.0, 5.0]);

        $this->assertFalse($grafica->conCurvas());
        $this->assertTrue($grafica->conPuntos());
        $this->assertCount(1, $grafica->puntosDelNino());
        $this->assertNull($grafica->finDeLaCurva('m'));
        $this->assertSame('', $grafica->franja());
    }

    public function test_sin_nada_no_falla(): void
    {
        $grafica = new GraficaDeCrecimiento('Peso', 'x', 'y', ['b' => [], 'm' => [], 'a' => []], []);

        $this->assertFalse($grafica->conCurvas());
        $this->assertFalse($grafica->conPuntos());
        $this->assertSame([], $grafica->puntosDelNino());
        $this->assertNotSame([], $grafica->marcasDelEjeY());
    }

    public function test_los_numeros_van_en_espanol_sin_ceros_de_mas(): void
    {
        $this->assertSame('12', GraficaDeCrecimiento::numero(12.0));
        $this->assertSame('12,5', GraficaDeCrecimiento::numero(12.5));
        $this->assertSame('0,25', GraficaDeCrecimiento::numero(0.25));
    }

    public function test_la_edad_en_texto_cuenta_anios_meses_y_dias(): void
    {
        $this->assertSame('2 años 4 meses 3 días', EdadEnTexto::entre('2024-01-01', '2026-05-04'));
        $this->assertSame('1 año', EdadEnTexto::entre('2025-05-04', '2026-05-04'));
        $this->assertSame('1 mes', EdadEnTexto::entre('2026-04-04', '2026-05-04'));
        $this->assertSame('0 días', EdadEnTexto::entre('2026-05-04', '2026-05-04'));
        $this->assertNull(EdadEnTexto::entre('2026-05-05', '2026-05-04'));
        $this->assertNull(EdadEnTexto::entre(null, '2026-05-04'));
    }
}
