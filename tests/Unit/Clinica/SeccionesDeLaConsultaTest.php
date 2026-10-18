<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\SeccionesDeLaConsulta;
use Tests\TestCase;

/**
 * **El catálogo de las secciones de la consulta** (revisión de Alexander, 2026-10-17): la fuente única que comparten el
 * shell y el grupo «Consulta» del menú lateral.
 *
 * Se prueba sin base de datos: lo único que necesita es el catálogo y el resolvedor de rutas.
 */
class SeccionesDeLaConsultaTest extends TestCase
{
    public function test_el_catalogo_tiene_los_catorce_items_en_el_orden_del_legado(): void
    {
        $todas = SeccionesDeLaConsulta::todas();

        $this->assertCount(14, $todas);
        $this->assertSame(14, SeccionesDeLaConsulta::total());
        $this->assertSame(range(1, 14), array_column($todas, 'numero'));
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Exámenes', 'Diagnóstico', 'Evolución',
            'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($todas, 'titulo'));

        // Trece con pantalla (la pre-factura llega con F9), agrupados como el legado.
        $this->assertSame(13, SeccionesDeLaConsulta::disponibles());
        $this->assertSame(['Consulta', 'Documentos'], array_values(array_unique(array_column($todas, 'grupo'))));
    }

    public function test_la_seccion_se_normaliza_contra_el_catalogo(): void
    {
        $this->assertSame('motivos', SeccionesDeLaConsulta::normalizar('motivos'));
        $this->assertTrue(SeccionesDeLaConsulta::existeSeccion('recipes'));
        // Un valor inventado, vacío o el de un ítem sin pantalla no abre ninguna sección.
        $this->assertNull(SeccionesDeLaConsulta::normalizar('prefactura'));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(''));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(null));
        $this->assertNull(SeccionesDeLaConsulta::porSeccion('no-existe'));
    }

    public function test_el_submenu_del_menu_lateral_lleva_a_cada_seccion_y_marca_el_activo(): void
    {
        $items = SeccionesDeLaConsulta::submenu(42, 'diagnostico');

        $this->assertCount(14, $items);
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Exámenes', 'Diagnóstico', 'Evolución',
            'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($items, 'texto'));

        // Cada ítem enlaza a la consulta que se está atendiendo, con su sección en la query.
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'motivos']), $items[0]['url']);
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'datos']), $items[12]['url']);
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'diagnostico']), $items[7]['url']);

        // Uno solo activo, y es el de la sección pedida.
        $activos = array_values(array_filter($items, fn (array $item) => $item['activo']));
        $this->assertCount(1, $activos);
        $this->assertSame('Diagnóstico', $activos[0]['texto']);

        // La pre-factura todavía no tiene pantalla: no ofrece enlace y va deshabilitada.
        $this->assertNull($items[13]['url']);
        $this->assertTrue($items[13]['deshabilitado']);
        $this->assertFalse($items[13]['activo']);

        // Sin sección (el shell recién abierto) no hay ningún ítem activo y el ítem sin pantalla sigue deshabilitado.
        $this->assertSame([], array_values(array_filter(SeccionesDeLaConsulta::submenu(42, null), fn (array $item) => $item['activo'])));
        $this->assertSame([], array_values(array_filter(SeccionesDeLaConsulta::submenu(42, 'prefactura'), fn (array $item) => $item['activo'])));
    }

    public function test_el_icono_y_el_estado_de_cada_item_salen_del_catalogo(): void
    {
        $items = SeccionesDeLaConsulta::submenu(7, null);

        $this->assertSame('bi-list-check', $items[0]['icono']);
        $this->assertSame('bi-receipt', $items[13]['icono']);
        $this->assertFalse($items[0]['deshabilitado']);
    }

    /**
     * El `detalle` de cada ítem ya no se pinta (el menú lleva sólo el nombre), pero sigue siendo el mapa de qué
     * contiene cada sección: el de Solicitudes nombra todo lo que cuelga de ella (WEB-5.3a, WEB-5.3b y WEB-5.4).
     */
    public function test_el_detalle_de_solicitudes_nombra_todo_lo_que_cuelga_de_ella(): void
    {
        $solicitudes = SeccionesDeLaConsulta::porSeccion(SeccionesDeLaConsulta::SECCION_SOLICITUDES);

        foreach (['Laboratorio', 'estudios', 'constancia', 'reposo', 'referencia', 'hospitalización', 'informe', 'dieta'] as $parte) {
            $this->assertStringContainsString($parte, $solicitudes['detalle']);
        }
    }
}
