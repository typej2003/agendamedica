<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\IndicadoresIncidentes;
use PHPUnit\Framework\TestCase;

/**
 * **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): el mapa del dato y el texto que compone.
 *
 * Lo que se fija, contra el escritorio:
 *  - cada campo del panel va a **su** columna de `examenes_paciente_consulta` (el `UPDATE` de
 *    `w_consulta_principal.srw:2924-2960`);
 *  - el texto (`consultas.resultadoexamencomp`) es `ETIQUETA: valor.` por cada campo **que entra en el resumen**, en
 *    el orden de `EXA_RESU` (`:25203`);
 *  - los gases, el volumen, el sodio urinario y la creatinina calculada **se guardan pero no entran** en el texto
 *    (sus líneas están comentadas en el fuente).
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
        // El sodio va después de la urea (orden de `EXA_RESU`), no el del formulario.
        $this->assertSame('UREA: 30. Na: 140. Ca: 9.5.', IndicadoresIncidentes::componer([
            'sodio' => '140', 'urea' => '30', 'calcio' => '9.5',
        ]));
    }

    public function test_las_etiquetas_son_las_del_escritorio(): void
    {
        $this->assertSame('Cr s: 1.2. HEMOGLOBINA: 11. Ac Uu: 0.6. Cr u 24h: 1.5.', IndicadoresIncidentes::componer([
            'creatinina' => '1.2', 'hemoglobina' => '11', 'creatinina_u24' => '1.5', 'acido_urico_u' => '0.6',
        ]));
    }

    public function test_los_gases_y_lo_que_el_escritorio_no_suma_al_texto_son_campos_pero_no_entran(): void
    {
        $claves = IndicadoresIncidentes::claves();

        // Son campos del panel (se guardan en su columna)…
        foreach (['ph', 'pco2', 'hco3', 'exceso_base', 'volumen', 'sodio_u', 'creatinina_s'] as $clave) {
            $this->assertContains($clave, $claves, 'Falta el campo ' . $clave . '.');
        }

        // …y no entran en el resumen: sin los campos del texto, el texto queda vacío.
        $this->assertSame('', IndicadoresIncidentes::componer([
            'ph' => '7.35', 'pco2' => '40', 'hco3' => '24', 'exceso_base' => '-1',
            'volumen' => '1200', 'sodio_u' => '80', 'creatinina_s' => '0.8',
        ]));
    }

    public function test_cada_campo_va_a_su_columna_de_la_tabla(): void
    {
        $campos = IndicadoresIncidentes::campos();

        // Los que el escritorio escribe con un nombre distinto al del rótulo (el mapa del `UPDATE`).
        $this->assertSame('cr', $campos['creatinina']['columna']);
        $this->assertSame('trasferrina', $campos['transferrina']['columna']);
        $this->assertSame('ac_urico_serico', $campos['acido_urico']['columna']);
        $this->assertSame('depuracion_creatinina', $campos['depuracion']['columna']);
        $this->assertSame('hb_glicosilada', $campos['hemoglobina_gli']['columna']);
        $this->assertSame('albumina_u', $campos['albumina']['columna']);
        $this->assertSame('creatinina_u_24', $campos['creatinina_u24']['columna']);
        $this->assertSame('g_v_ph', $campos['ph']['columna']);
        $this->assertSame('v_urina', $campos['volumen']['columna']);

        // Y todos los campos apuntan a una columna real de la tabla del panel.
        $this->assertCount(33, IndicadoresIncidentes::claves());
        $this->assertCount(33, IndicadoresIncidentes::columnas());
    }

    public function test_los_grupos_son_sangre_orina_y_gases(): void
    {
        $this->assertSame(['Sangre', 'Orina', 'Gases'], array_keys(IndicadoresIncidentes::grupos()));
    }

    public function test_un_numero_se_muestra_sin_ceros_de_mas(): void
    {
        $this->assertSame('30', IndicadoresIncidentes::comoTexto(30.0));
        $this->assertSame('1.2', IndicadoresIncidentes::comoTexto(1.2));
        $this->assertSame('0.85', IndicadoresIncidentes::comoTexto('0.850'));
        $this->assertNull(IndicadoresIncidentes::comoTexto(null));
    }
}
