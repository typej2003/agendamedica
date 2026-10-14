<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\ResumenMedico;
use PHPUnit\Framework\TestCase;

/**
 * **El resumen médico de una consulta** (WEB-5.3c), sin base de datos: entradas ya leídas → los textos que
 * se imprimen, con los rótulos literales del `d_resumen_medico_gra` y sus subreportes, en su orden.
 *
 * Acá vive el contrato de la hoja: el orden de los ocho bloques, los rótulos, que un bloque sin datos no se
 * imprima y que el `para` y la fecha de la cabecera no hagan un documento por sí solos. La lectura de la
 * base (qué columna alimenta cada bloque) es `ResumenMedicoDeLaConsulta` y se fija en los tests de la web.
 */
class ResumenMedicoTest extends TestCase
{
    /** @return array<string,mixed> los ocho bloques con datos */
    private function completo(): array
    {
        return [
            ResumenMedico::MOTIVO_DE_CONSULTA => ['DOLOR ABDOMINAL', 'CONTROL'],
            ResumenMedico::ENFERMEDAD_ACTUAL => "Dolor de dos días.\nSin fiebre.",
            ResumenMedico::EXAMEN_FISICO => 'Abdomen blando, depresible.',
            ResumenMedico::EXAMEN_GINECOLOGICO => 'Genitales externos normales.',
            ResumenMedico::RESULTADO_DE_EXAMENES => 'Hemograma dentro de límites.',
            ResumenMedico::DIAGNOSTICO => ['GASTRITIS', 'ANEMIA LEVE'],
            ResumenMedico::TRATAMIENTO => ['OMEPRAZOL/Cap. 20 mg', 'HIERRO/Tabletas'],
            ResumenMedico::PLAN_DE_TRATAMIENTO => 'Control en un mes.',
        ];
    }

    public function test_los_ocho_bloques_salen_en_el_orden_del_datawindow_con_sus_rotulos_literales(): void
    {
        $resumen = new ResumenMedico($this->completo(), 'A QUIEN CORRESPONDA', '2026-10-06');

        $this->assertTrue($resumen->esDocumento());
        $this->assertSame('A QUIEN CORRESPONDA', $resumen->para());
        $this->assertSame('2026-10-06', $resumen->fecha());

        $this->assertSame([
            'MOTIVO DE CONSULTA:',
            'ENFERMEDAD ACTUAL:',
            'EXAMEN FÍSICO:',
            'EXAMEN GINECOLÓGICO:',
            'RESULTADO DE EXÁMENES:',
            // El rótulo del diagnóstico va **sin** dos puntos: es el `text` del DataWindow y así sale.
            'DIAGNOSTICO',
            'TRATAMIENTO:',
            'PLAN DE TRATAMIENTO:',
        ], $resumen->rotulos());
    }

    public function test_los_bloques_de_lista_se_unen_con_el_separador_de_la_hoja_y_se_parten_entre_elementos(): void
    {
        $resumen = new ResumenMedico($this->completo());

        $parrafos = $resumen->parrafos();

        // El motivo, los diagnósticos y el tratamiento son los tres bloques de lista.
        $this->assertSame(['DOLOR ABDOMINAL', 'CONTROL'], $parrafos[0]['items']);
        $this->assertSame('DOLOR ABDOMINAL · CONTROL', $parrafos[0]['texto']);

        $diagnostico = collect($parrafos)->firstWhere('etiqueta', 'DIAGNOSTICO');
        $this->assertSame('GASTRITIS · ANEMIA LEVE', $diagnostico['texto']);
        $this->assertSame(['GASTRITIS', 'ANEMIA LEVE'], $diagnostico['items']);

        $tratamiento = collect($parrafos)->firstWhere('etiqueta', 'TRATAMIENTO:');
        $this->assertSame('OMEPRAZOL/Cap. 20 mg · HIERRO/Tabletas', $tratamiento['texto']);
        $this->assertSame(['OMEPRAZOL/Cap. 20 mg', 'HIERRO/Tabletas'], $tratamiento['items']);
    }

    public function test_los_bloques_de_texto_salen_un_parrafo_por_renglon_con_el_rotulo_arriba(): void
    {
        $resumen = new ResumenMedico($this->completo());

        $parrafos = $resumen->parrafos();

        // La enfermedad actual: el rótulo en el primer renglón y el texto tal como lo escribió el médico.
        $this->assertSame('ENFERMEDAD ACTUAL:', $parrafos[1]['etiqueta']);
        $this->assertSame('Dolor de dos días.', $parrafos[1]['texto']);
        $this->assertTrue($parrafos[1]['aparte']);
        $this->assertNull($parrafos[2]['etiqueta']);
        $this->assertSame('Sin fiebre.', $parrafos[2]['texto']);

        // El examen físico, el ginecológico, el resultado de exámenes y el plan: un párrafo cada uno.
        $this->assertSame('Abdomen blando, depresible.', collect($parrafos)->firstWhere('etiqueta', 'EXAMEN FÍSICO:')['texto']);
        $this->assertSame('Genitales externos normales.', collect($parrafos)->firstWhere('etiqueta', 'EXAMEN GINECOLÓGICO:')['texto']);
        $this->assertSame('Hemograma dentro de límites.', collect($parrafos)->firstWhere('etiqueta', 'RESULTADO DE EXÁMENES:')['texto']);
        $this->assertSame('Control en un mes.', collect($parrafos)->firstWhere('etiqueta', 'PLAN DE TRATAMIENTO:')['texto']);
    }

    public function test_un_bloque_sin_datos_no_imprime_su_rotulo(): void
    {
        // Sólo la enfermedad actual y los diagnósticos (WEB-5.3c: los bloques vacíos se omiten).
        $resumen = new ResumenMedico([
            ResumenMedico::ENFERMEDAD_ACTUAL => 'Dolor de dos días.',
            ResumenMedico::DIAGNOSTICO => 'GASTRITIS',
        ]);

        $this->assertTrue($resumen->esDocumento());
        $this->assertSame(['ENFERMEDAD ACTUAL:', 'DIAGNOSTICO'], $resumen->rotulos());

        $html = '';
        foreach ($resumen->parrafos() as $parrafo) {
            $html .= $parrafo['etiqueta'] ?? '';
        }

        foreach (['MOTIVO DE CONSULTA:', 'EXAMEN FÍSICO:', 'EXAMEN GINECOLÓGICO:', 'RESULTADO DE EXÁMENES:', 'TRATAMIENTO:', 'PLAN DE TRATAMIENTO:'] as $rotulo) {
            $this->assertStringNotContainsString($rotulo, $html, "El rótulo {$rotulo} no debía imprimirse.");
        }
    }

    public function test_los_vacios_del_legado_no_cuentan_como_datos(): void
    {
        // `none` es el literal que deja el DataWindow del escritorio, y el `''` es el mismo estado.
        $resumen = new ResumenMedico([
            ResumenMedico::ENFERMEDAD_ACTUAL => 'none',
            ResumenMedico::EXAMEN_FISICO => '   ',
            ResumenMedico::RESULTADO_DE_EXAMENES => null,
            ResumenMedico::MOTIVO_DE_CONSULTA => ['', 'none', '  '],
            ResumenMedico::TRATAMIENTO => [],
        ]);

        $this->assertFalse($resumen->esDocumento());
        $this->assertSame([], $resumen->parrafos());
        $this->assertSame([], $resumen->rotulos());
    }

    public function test_el_para_y_la_fecha_de_la_cabecera_no_hacen_un_documento(): void
    {
        // Un informe con «Para» y fecha pero sin ningún dato clínico no es un resumen médico: la cabecera
        // sola no se imprime (misma vara que la fecha del eco pélvico).
        $resumen = new ResumenMedico([], 'A QUIEN CORRESPONDA', '2026-10-06');

        $this->assertFalse($resumen->esDocumento());
        $this->assertSame('A QUIEN CORRESPONDA', $resumen->para());
        $this->assertSame('2026-10-06', $resumen->fecha());
    }

    /**
     * El **tratamiento solo** no hace un resumen médico: los medicamentos ya tienen su récipe. El bloque
     * sí se imprime cuando la hoja sale (el test de arriba), pero no alcanza para ofrecerla.
     */
    public function test_el_tratamiento_solo_no_hace_un_documento_pero_si_se_imprime_cuando_la_hoja_sale(): void
    {
        $soloTratamiento = new ResumenMedico([
            ResumenMedico::TRATAMIENTO => ['OMEPRAZOL/Cap. 20 mg'],
        ]);

        $this->assertFalse($soloTratamiento->esDocumento());
        // Con cualquier otro bloque, sí.
        $this->assertTrue((new ResumenMedico([
            ResumenMedico::TRATAMIENTO => ['OMEPRAZOL/Cap. 20 mg'],
            ResumenMedico::PLAN_DE_TRATAMIENTO => 'Control en un mes.',
        ]))->esDocumento());

        // Y cuando la hoja sale con otros datos, el tratamiento va con ellos.
        $conEnfermedad = new ResumenMedico([
            ResumenMedico::ENFERMEDAD_ACTUAL => 'Dolor de dos días.',
            ResumenMedico::TRATAMIENTO => ['OMEPRAZOL/Cap. 20 mg'],
        ]);

        $this->assertSame(['ENFERMEDAD ACTUAL:', 'TRATAMIENTO:'], $conEnfermedad->rotulos());
    }

    public function test_los_elementos_de_una_lista_se_recortan_y_los_espacios_de_mas_se_colapsan(): void
    {
        $resumen = new ResumenMedico([
            ResumenMedico::MOTIVO_DE_CONSULTA => ['  DOLOR   ABDOMINAL ', 'none', 'CONTROL'],
            // Un texto de varias líneas que quedó separado por espacios: se recorta, las líneas vacías no imprimen.
            ResumenMedico::ENFERMEDAD_ACTUAL => "  Dolor de dos días.  \n\n  Sin fiebre.  ",
        ]);

        $parrafos = $resumen->parrafos();

        $this->assertSame(['DOLOR ABDOMINAL', 'CONTROL'], $parrafos[0]['items']);
        $this->assertSame('DOLOR ABDOMINAL · CONTROL', $parrafos[0]['texto']);
        $this->assertSame('Dolor de dos días.', $parrafos[1]['texto']);
        $this->assertSame('Sin fiebre.', $parrafos[2]['texto']);
    }
}
