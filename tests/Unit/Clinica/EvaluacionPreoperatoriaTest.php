<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EvaluacionPreoperatoria;
use PHPUnit\Framework\TestCase;

/**
 * **Evaluación pre-operatoria** (cirugía pediátrica, 2026-10-19): el mapa del dato.
 *
 * Lo que se fija: la tabla del escritorio (`pre_aneste`, una fila por consulta) y sus tres columnas, con
 * `emergencia` de un carácter (`S`/`N`, el `'S'` contra el que compara `w_consulta_principal.srw:14344`).
 */
class EvaluacionPreoperatoriaTest extends TestCase
{
    public function test_los_campos_son_los_de_la_tabla(): void
    {
        $campos = EvaluacionPreoperatoria::campos();

        $this->assertSame(['tipo', 'resultado', 'emergencia'], array_keys($campos));
        $this->assertSame('pre_aneste', EvaluacionPreoperatoria::TABLA);
        $this->assertSame(['tipo', 'resultado', 'emergencia'], EvaluacionPreoperatoria::columnas());
        $this->assertSame('opcion', $campos['tipo']['clase']);
        $this->assertSame('si_no', $campos['emergencia']['clase']);
    }

    public function test_la_emergencia_se_lee_como_si_o_no(): void
    {
        $this->assertSame('Sí', EvaluacionPreoperatoria::emergenciaLegible('S'));
        $this->assertSame('No', EvaluacionPreoperatoria::emergenciaLegible('N'));
        $this->assertNull(EvaluacionPreoperatoria::emergenciaLegible(null));
        $this->assertSame('S', EvaluacionPreoperatoria::EMERGENCIA_SI);
    }
}
