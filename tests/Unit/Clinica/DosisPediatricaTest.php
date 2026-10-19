<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\DosisPediatrica;
use PHPUnit\Framework\TestCase;

/**
 * **La dosis pediátrica por peso** (2026-10-19): la fórmula del escritorio, copiada de
 * `pw9_ws/w_agregar_nueva_gesta_medicamento.srw:223-254`.
 *
 * Lo que se fija, con los casos del fuente:
 *  - el peso se **topea en 30 kg** (arriba de eso, dosis de adulto);
 *  - la posología en mg por toma es `(dosis × peso) / cada`;
 *  - con presentación (`pvc` mg / `pvs` ml) pasa a **ml**, y con concentración a **gotas**;
 *  - el rótulo usa `24 / cada` **truncado a entero** (la rareza del `integer hot` del escritorio);
 *  - si el medicamento no es por kilo (`dosificacion <> 'SI'`), no hay cálculo.
 */
class DosisPediatricaTest extends TestCase
{
    private function medicamento(array $datos = []): object
    {
        return (object) ($datos + [
            'dosificacion' => 'SI',
            'dosis'        => 10,
            'cada'         => 8,
            'durante'      => 5,
            'pvc'          => null,
            'pvs'          => null,
            'concentracion' => null,
            'uso'          => 'Según indicación.',
        ]);
    }

    public function test_el_peso_se_topea_en_treinta_kilos(): void
    {
        $this->assertSame(12.0, DosisPediatrica::pesoParaLaDosis(12));
        $this->assertSame(30.0, DosisPediatrica::pesoParaLaDosis(45));
        $this->assertSame(30.0, DosisPediatrica::pesoParaLaDosis(30));
        $this->assertNull(DosisPediatrica::pesoParaLaDosis(null));
        $this->assertNull(DosisPediatrica::pesoParaLaDosis(0));
        $this->assertNull(DosisPediatrica::pesoParaLaDosis(''));
    }

    public function test_la_posologia_es_dosis_por_peso_entre_cada(): void
    {
        // 10 mg/kg × 12 kg / 8 h = 15 mg por toma.
        $calculo = (new DosisPediatrica())->calcular($this->medicamento(), 12);

        $this->assertNotNull($calculo);
        $this->assertSame(12.0, $calculo['peso']);
        $this->assertEqualsWithDelta(15.0, $calculo['posologia'], 0.001);
        $this->assertSame(3, $calculo['cadaHoras']); // 24 / 8
        $this->assertNull($calculo['ml']);
        $this->assertNull($calculo['gotas']);
        // La rama sin presentación escribe «mg» y «días» en minúscula, como el escritorio (`:249`).
        $this->assertSame('DAR 15 mg 8 veces al día por 5 días', $calculo['texto']);
    }

    public function test_con_presentacion_pasa_a_mililitros(): void
    {
        // 10 mg/kg × 12 kg / 8 h = 15 mg; el envase tiene 250 mg en 5 ml → 5 × 15 / 250 = 0.3 ml.
        $calculo = (new DosisPediatrica())->calcular($this->medicamento(['pvc' => 250, 'pvs' => 5]), 12);

        $this->assertEqualsWithDelta(0.3, $calculo['ml'], 0.001);
        $this->assertNull($calculo['gotas']);
        $this->assertSame('DAR 0.3 ML CADA 3 HORAS POR 5 DÍAS', $calculo['texto']);
    }

    public function test_con_concentracion_pasa_a_gotas(): void
    {
        // Los mismos 0.3 ml, con 20 gotas por ml → 6 gotas.
        $calculo = (new DosisPediatrica())->calcular(
            $this->medicamento(['pvc' => 250, 'pvs' => 5, 'concentracion' => 20]),
            12,
        );

        $this->assertEqualsWithDelta(6.0, $calculo['gotas'], 0.001);
        $this->assertSame('DAR 6 GOTAS CADA 3 HORAS POR 5 DÍAS', $calculo['texto']);
    }

    public function test_un_nino_de_mas_de_treinta_kilos_usa_la_dosis_de_adulto(): void
    {
        $calculo = (new DosisPediatrica())->calcular($this->medicamento(), 45);

        $this->assertSame(30.0, $calculo['peso']);
        // 10 × 30 / 8 = 37.5 mg.
        $this->assertEqualsWithDelta(37.5, $calculo['posologia'], 0.001);
    }

    public function test_las_horas_del_rotulo_son_el_cociente_truncado(): void
    {
        // La rareza del escritorio: `integer hot = 24 / CADA`, así que «cada 5 horas» se imprime «CADA 4 HORAS».
        // (Las horas sólo salen en las dos ramas con presentación: la de mg usa el «cada» tal cual.)
        $calculo = (new DosisPediatrica())->calcular(
            $this->medicamento(['cada' => 5, 'pvc' => 250, 'pvs' => 5]),
            10,
        );

        $this->assertSame(4, $calculo['cadaHoras']);
        $this->assertStringContainsString('CADA 4 HORAS', $calculo['texto']);
    }

    public function test_sin_peso_o_sin_ser_por_kilo_no_hay_calculo(): void
    {
        $this->assertNull((new DosisPediatrica())->calcular($this->medicamento(), null));
        $this->assertNull((new DosisPediatrica())->calcular($this->medicamento(['dosificacion' => 'NO']), 12));
        $this->assertNull((new DosisPediatrica())->calcular($this->medicamento(['cada' => 0]), 12));
    }
}
