<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\ArmarResumenDelExamen;
use PHPUnit\Framework\TestCase;

/**
 * **El «Actualizar» del resumen físico del internista** (Alexander, 2026-10-29): la misma cuenta de `ue_resume` del
 * escritorio de medicina interna (`w_consulta_principal.srw:22456`), con los signos de una solapa y el examen de otra.
 *
 * Sin base de datos: los valores entran ya leídos y el texto sale formateado. Fija el orden y los rótulos del
 * escritorio, que el IMC se calcula con peso y talla, y que la tensión arterial es la acostada (con la sentada y la
 * de pie como respaldo).
 */
class ArmarResumenDelInternistaTest extends TestCase
{
    public function test_arma_el_resumen_con_el_orden_y_los_rotulos_del_escritorio(): void
    {
        $texto = ArmarResumenDelExamen::internista(
            [
                'peso' => '80', 'talla' => '1,60', 'cintura' => '95', 'pp' => '18', 'fc' => '72',
                'taacostado' => '120/80', 'tasentado' => '130/85',
            ],
            ['genera' => 'Buen estado', 'ojo_de' => 'Normal', 'cabeza' => 'none', 'otros' => 'Sin hallazgos'],
        );

        $this->assertSame(
            'PESO: 80 kg.  TALLA: 1,60 m.  IMC: 31,25.  C. ABDOMINAL: 95 cm.  F. RESPIRATORIA: 18.  '
            . 'F. CARDIACA: 72.  TENSIÓN ARTERIAL: 120/80 mm/Hg.  GENERAL: Buen estado.  OJOS: Normal.  '
            . 'OTROS: Sin hallazgos.  .',
            $texto,
        );
    }

    public function test_la_tension_usa_la_acostada_y_si_falta_la_sentada_o_la_de_pie(): void
    {
        $this->assertStringContainsString('TENSIÓN ARTERIAL: 130/85 mm/Hg.', ArmarResumenDelExamen::internista(
            ['taacostado' => '', 'tasentado' => '130/85'],
            [],
        ));

        $this->assertStringContainsString('TENSIÓN ARTERIAL: 140/90 mm/Hg.', ArmarResumenDelExamen::internista(
            ['taacostado' => 'none', 'tasentado' => '', 'tapie' => '140/90'],
            [],
        ));
    }

    public function test_sin_peso_o_sin_talla_no_hay_imc(): void
    {
        $this->assertStringNotContainsString('IMC:', ArmarResumenDelExamen::internista(['peso' => '80'], []));
        $this->assertStringNotContainsString('IMC:', ArmarResumenDelExamen::internista(['talla' => '1,60'], []));
    }

    public function test_un_examen_sin_datos_no_arma_nada(): void
    {
        $this->assertSame('', ArmarResumenDelExamen::internista([], []));
        $this->assertSame('', ArmarResumenDelExamen::internista(['peso' => '', 'cabeza' => 'none'], ['genera' => '  ']));
    }
}
