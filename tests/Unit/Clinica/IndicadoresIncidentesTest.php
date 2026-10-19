<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\IndicadoresIncidentes;
use PHPUnit\Framework\TestCase;

/**
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): el texto que compone el panel.
 *
 * La regla que se fija es la del escritorio (`w_consulta_principal.srw:25203`): `ETIQUETA: valor.` por cada campo
 * cargado, en ese orden y con esas etiquetas. Lo que queda guardado en `consultas.resultadoexamencomp` es ese
 * texto, y es lo que imprimen el resumen médico y la evaluación pre-operatoria.
 */
class IndicadoresIncidentesTest extends TestCase
{
    public function test_sin_valores_no_hay_texto(): void
    {
        $this->assertSame('', IndicadoresIncidentes::componer([]));
        $this->assertSame('', IndicadoresIncidentes::componer(['urea' => '', 'sodio' => '  ']));
    }

    public function test_compone_un_parrafo_por_cada_campo_cargado_en_el_orden_del_escritorio(): void
    {
        $texto = IndicadoresIncidentes::componer([
            'sodio'  => '140',
            'urea'   => '30',
            'calcio' => '9,5',
        ]);

        // Urea va antes que el sodio (orden de `EXA_RESU`), no el del formulario; la coma decimal se respeta.
        $this->assertSame('UREA: 30. Na: 140. Ca: 9,5.', $texto);
    }

    public function test_las_etiquetas_son_las_del_escritorio(): void
    {
        $texto = IndicadoresIncidentes::componer([
            'creatinina'     => '1.2',
            'hemoglobina'    => '11',
            'creatinina_u24' => '1.5',
            'acido_urico_u'  => '0.6',
        ]);

        $this->assertSame('Cr s: 1.2. HEMOGLOBINA: 11. Ac Uu: 0.6. Cr u 24h: 1.5.', $texto);
    }

    public function test_los_campos_que_el_escritorio_descarta_no_estan(): void
    {
        // Los gases, el exceso de base, el volumen y el sodio urinario se leen en el escritorio pero no entran en
        // el texto (las líneas están comentadas). La web no ofrece campos que no se guarden.
        $claves = IndicadoresIncidentes::claves();

        foreach (['ph', 'pco', 'hco', 'exceso_base', 'volumen', 'sodio_u'] as $clave) {
            $this->assertNotContains($clave, $claves, 'No debe ofrecerse el campo ' . $clave . '.');
        }

        $this->assertCount(26, $claves);
    }

    public function test_los_grupos_son_sangre_y_orina(): void
    {
        $this->assertSame(['Sangre', 'Orina'], array_keys(IndicadoresIncidentes::grupos()));
    }
}
