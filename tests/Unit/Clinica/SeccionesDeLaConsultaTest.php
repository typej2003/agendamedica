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
    public function test_el_catalogo_tiene_los_trece_items_en_el_orden_del_legado(): void
    {
        $todas = SeccionesDeLaConsulta::todas('ginecologia-y-obstetricia');

        // O-22 (2026-10-17) retiró *Exámenes* (el ítem 7): la numeración del legado **no se renumera**, así
        // que queda el hueco del 7 y son 13 ítems.
        $this->assertCount(13, $todas);
        $this->assertSame(13, SeccionesDeLaConsulta::total('ginecologia-y-obstetricia'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13, 14], array_column($todas, 'numero'));
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Diagnóstico', 'Evolución',
            'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($todas, 'titulo'));

        // Los trece con pantalla (la pre-factura llegó con F9), agrupados como el legado.
        $this->assertSame(13, SeccionesDeLaConsulta::disponibles('ginecologia-y-obstetricia'));
        $this->assertSame(['Consulta', 'Documentos'], array_values(array_unique(array_column($todas, 'grupo'))));
    }

    /**
     * **Sin especialidad el catálogo es el del núcleo** (2026-10-30): once ítems, sin el estudio ecográfico (5) ni el
     * control de embarazo (6). Antes el `null` se tomaba como ginecología y el médico sin especialidad veía los trece.
     */
    public function test_sin_especialidad_el_catalogo_es_el_del_nucleo(): void
    {
        $todas = SeccionesDeLaConsulta::todas();

        $this->assertCount(11, $todas);
        $this->assertSame([1, 2, 3, 4, 8, 9, 10, 11, 12, 13, 14], array_column($todas, 'numero'));
        $this->assertNotContains('Estudio ecográfico', array_column($todas, 'titulo'));
        $this->assertNotContains('Control de embarazo', array_column($todas, 'titulo'));
    }

    public function test_la_seccion_se_normaliza_contra_el_catalogo(): void
    {
        $this->assertSame('motivos', SeccionesDeLaConsulta::normalizar('motivos'));
        $this->assertTrue(SeccionesDeLaConsulta::existeSeccion('recipes'));
        // La pre-factura es una sección desde F9; un valor inventado o vacío no abre ninguna.
        $this->assertSame('prefactura', SeccionesDeLaConsulta::normalizar('prefactura'));
        $this->assertNull(SeccionesDeLaConsulta::normalizar('no-existe'));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(''));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(null));
        $this->assertNull(SeccionesDeLaConsulta::porSeccion('no-existe'));
        // O-22: `examenes` dejó de ser una sección (el controlador redirige los enlaces viejos).
        $this->assertNull(SeccionesDeLaConsulta::normalizar(SeccionesDeLaConsulta::SECCION_EXAMENES));
        $this->assertFalse(SeccionesDeLaConsulta::existeSeccion(SeccionesDeLaConsulta::SECCION_EXAMENES));
        $this->assertNull(SeccionesDeLaConsulta::porSeccion('examenes'));
    }

    public function test_el_submenu_del_menu_lateral_lleva_a_cada_seccion_y_marca_el_activo(): void
    {
        $items = SeccionesDeLaConsulta::submenu(42, 'diagnostico', 'ginecologia-y-obstetricia');

        $this->assertCount(13, $items);
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Diagnóstico', 'Evolución',
            'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($items, 'texto'));

        // Cada ítem enlaza a la consulta que se está atendiendo, con su sección en la query.
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'motivos']), $items[0]['url']);
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'datos']), $items[11]['url']);
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'diagnostico']), $items[6]['url']);

        // Uno solo activo, y es el de la sección pedida.
        $activos = array_values(array_filter($items, fn (array $item) => $item['activo']));
        $this->assertCount(1, $activos);
        $this->assertSame('Diagnóstico', $activos[0]['texto']);

        // La pre-factura (F9) también enlaza a su sección.
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'prefactura']), $items[12]['url']);
        $this->assertFalse($items[12]['deshabilitado']);
        $this->assertFalse($items[12]['activo']);

        // «Exámenes» ya no está: no hay ítem ni enlace a esa sección.
        $this->assertNotContains('Exámenes', array_column($items, 'texto'));

        // Sin sección (el shell recién abierto) o con una inventada no hay ningún ítem activo.
        $this->assertSame([], array_values(array_filter(SeccionesDeLaConsulta::submenu(42, null), fn (array $item) => $item['activo'])));
        $this->assertSame([], array_values(array_filter(SeccionesDeLaConsulta::submenu(42, 'no-existe'), fn (array $item) => $item['activo'])));
    }

    public function test_el_icono_y_el_estado_de_cada_item_salen_del_catalogo(): void
    {
        $items = SeccionesDeLaConsulta::submenu(7, null, 'ginecologia-y-obstetricia');

        $this->assertSame('bi-list-check', $items[0]['icono']);
        $this->assertSame('bi-receipt', $items[12]['icono']);
        $this->assertFalse($items[0]['deshabilitado']);
    }

    /**
     * O-06 (2026-10-17): sin `?seccion=` la consulta abre en **Motivos de consulta** (el ítem 1) y el menú lo
     * marca. Una sección pedida se respeta tal cual —aunque no exista—: el estado vacío sigue siendo su
     * destino.
     */
    public function test_sin_seccion_la_consulta_abre_en_motivos(): void
    {
        $this->assertSame('motivos', SeccionesDeLaConsulta::deEntrada(null));
        $this->assertSame('motivos', SeccionesDeLaConsulta::deEntrada(''));

        // Con el mismo valor por defecto, el grupo «Consulta» marca el ítem 1 y no otro.
        $items = SeccionesDeLaConsulta::submenu(42, SeccionesDeLaConsulta::deEntrada(null));
        $activos = array_values(array_filter($items, fn (array $item) => $item['activo']));
        $this->assertCount(1, $activos);
        $this->assertSame('Motivos de consulta', $activos[0]['texto']);

        // Una sección válida sigue siendo la suya; una inventada no se normaliza y cae en el vacío.
        $this->assertSame('evolucion', SeccionesDeLaConsulta::deEntrada('evolucion'));
        $this->assertSame('no-existe', SeccionesDeLaConsulta::deEntrada('no-existe'));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(SeccionesDeLaConsulta::deEntrada('no-existe')));
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
