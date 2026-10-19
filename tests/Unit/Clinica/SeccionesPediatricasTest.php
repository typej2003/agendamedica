<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\SeccionesDeLaConsulta;
use Tests\TestCase;

/**
 * **El catálogo de la consulta pediátrica** (2026-10-19): pediatría no usa el de ginecología.
 *
 * Los ítems son los del árbol de PediaReport (`w_consulta_principal.srw:2634-2715`), con su numeración: sin
 * estudio ecográfico ni control de embarazo. El **ítem 5 «Indicadores incidentes»** es el panel de nefrología
 * pediátrica: sólo aparece si el médico tiene ese postgrado, y esa es la primera prueba de que las secciones
 * que enciende un postgrado se suman al catálogo.
 *
 * Sin base de datos: el catálogo es PHP y las secciones del postgrado llegan resueltas como claves.
 */
class SeccionesPediatricasTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function items(array $seccionesPostgrados = []): array
    {
        return SeccionesDeLaConsulta::todas('pediatria', $seccionesPostgrados);
    }

    public function test_un_pediatra_general_tiene_los_catorce_items_de_su_arbol(): void
    {
        $items = $this->items();

        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Exámenes/Estudios', 'Inmunizaciones', 'Diagnóstico', 'Alimentación', 'Evolución',
            'Récipes', 'Solicitudes', 'Datos del paciente', 'Representante', 'Pre-factura',
        ], array_column($items, 'titulo'));

        // La numeración es la del legado: el 5 queda libre para el panel de nefrología y el 15 es la pre-factura.
        $this->assertSame([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], array_column($items, 'numero'));

        // Sin el estudio ecográfico ni el control de embarazo.
        $this->assertNotContains('Estudio ecográfico', array_column($items, 'titulo'));
        $this->assertNotContains('Control de embarazo', array_column($items, 'titulo'));

        // Trece con pantalla: sólo falta la pre-factura.
        $this->assertSame(13, SeccionesDeLaConsulta::disponibles('pediatria'));
    }

    public function test_el_panel_de_nefrologia_es_el_item_cinco_y_solo_con_ese_postgrado(): void
    {
        // Sin el postgrado no existe: ni el ítem ni la sección.
        $this->assertNull(SeccionesDeLaConsulta::porSeccion(SeccionesDeLaConsulta::SECCION_INDICADORES, 'pediatria'));
        $this->assertNull(SeccionesDeLaConsulta::normalizar(SeccionesDeLaConsulta::SECCION_INDICADORES, 'pediatria'));

        // Con nefrología pediátrica (la sección la resuelve el contexto desde el manifiesto) se inserta en su lugar.
        $items = $this->items(['indicadores']);
        $titulos = array_column($items, 'titulo');

        $this->assertCount(15, $items);
        $this->assertSame('Indicadores incidentes', $titulos[4]);
        $this->assertSame(5, $items[4]['numero']);
        // Va después de la evaluación física y antes de exámenes/estudios, como en el escritorio.
        $this->assertSame('Evaluación física', $titulos[3]);
        $this->assertSame('Exámenes/Estudios', $titulos[5]);

        // Sigue sin pantalla: se ofrece en el menú como «Próximamente», no como un enlace muerto.
        $this->assertSame('proximamente', $items[4]['estado']);
        $this->assertSame(SeccionesDeLaConsulta::SECCION_INDICADORES, SeccionesDeLaConsulta::normalizar(SeccionesDeLaConsulta::SECCION_INDICADORES, 'pediatria', ['indicadores']));
    }

    public function test_una_seccion_de_postgrado_que_el_catalogo_no_conoce_no_rompe_la_lista(): void
    {
        // El manifiesto declara secciones que todavía no tienen pantalla (neumología, endoscopías…): el catálogo
        // las ignora en vez de inventar un ítem.
        $items = $this->items(['neumologia', 'no-existe']);

        $this->assertCount(14, $items);
        $this->assertNotContains('Neumología', array_column($items, 'titulo'));
    }

    public function test_el_menu_pediatrico_lleva_a_cada_seccion_y_marca_la_activa(): void
    {
        $items = SeccionesDeLaConsulta::submenu(42, 'inmunizaciones', 'pediatria');

        $this->assertCount(14, $items);
        $this->assertSame(route('clinica.consulta', ['consulta' => 42, 'seccion' => 'inmunizaciones']), $items[5]['url']);

        $activos = array_values(array_filter($items, fn (array $item) => $item['activo']));
        $this->assertCount(1, $activos);
        $this->assertSame('Inmunizaciones', $activos[0]['texto']);

        // La pre-factura no tiene pantalla: sin enlace y deshabilitada.
        $this->assertNull($items[13]['url']);
        $this->assertTrue($items[13]['deshabilitado']);
    }
}
