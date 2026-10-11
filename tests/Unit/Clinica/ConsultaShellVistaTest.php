<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel del **shell de la consulta** (WEB-4.1) se prueba sin base de datos ni HTTP: datos de
 * entrada → textos de salida (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá: el encabezado (paciente, consulta, historia y fecha), que el panel tenga los
 * **13 ítems del legado** en su orden y con su tarea, que el único disponible al cerrar 4.1 sea la ficha
 * del paciente y que lo que todavía no existe diga "Próximamente" en vez de ofrecer un enlace muerto.
 */
class ConsultaShellVistaTest extends TestCase
{
    private function paciente(array $campos = []): object
    {
        return (object) array_merge([
            'id'          => 5619,
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'numhistoria' => '1001',
        ], $campos);
    }

    private function vista(array $extra = []): ConsultaShellVista
    {
        return new ConsultaShellVista(
            $extra['paciente'] ?? $this->paciente(),
            $extra['nroconsulta'] ?? 3,
            $extra['fecha'] ?? '2026-10-05',
            $extra['urlPaciente'] ?? '/clinica/pacientes/5619',
            $extra['urlHistoria'] ?? '/clinica/pacientes/5619/historia',
            $extra['urlVolver'] ?? '/clinica/agenda?vista=dia&fecha=2026-10-05&sede=todas',
        );
    }

    public function test_el_encabezado_lleva_el_paciente_la_consulta_la_historia_y_la_fecha(): void
    {
        $vm = $this->vista();

        $this->assertSame('Pérez, Ana', $vm->nombre());
        $this->assertSame('H. 1001', $vm->historia());
        $this->assertSame('Consulta N.º 3 · H. 1001 · 05/10/2026', $vm->subtitulo());
        $this->assertSame('05/10/2026', $vm->fecha());
        $this->assertSame(3, $vm->nroconsulta());
    }

    public function test_el_encabezado_tolera_los_datos_basura_del_legado(): void
    {
        // Una fecha `0000-00-00` y un nombre vacío no pueden romper la pantalla (`FechaClinica`).
        $vm = $this->vista([
            'paciente' => $this->paciente(['nombres' => '', 'apellidos' => '', 'numhistoria' => '']),
            'fecha'    => '0000-00-00',
        ]);

        $this->assertSame('Paciente sin nombre', $vm->nombre());
        $this->assertNull($vm->historia());
        $this->assertSame('—', $vm->fecha());
        $this->assertSame('Consulta N.º 3 · —', $vm->subtitulo());
    }

    public function test_el_panel_tiene_los_trece_items_del_legado_en_su_orden_y_con_su_tarea(): void
    {
        $vm = $this->vista();
        $panel = $vm->panel();

        $this->assertCount(2, $panel, 'el panel se agrupa como el legado: la atención y los documentos');

        $items = [];
        foreach ($panel as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[] = $item;
            }
        }

        $this->assertCount(13, $items);
        $this->assertSame(13, $vm->totalItems());

        // Los títulos, en el orden del panel de `w_consulta_principal` (wiki 04-historia-clinica.md).
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Exámenes', 'Diagnóstico', 'Evolución',
            'Récipes', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($items, 'titulo'));

        // Y cada uno con la tarea que lo completa: es el mapa del shell, no decoración.
        $this->assertSame([
            'WEB-4.2', 'WEB-4.2', 'WEB-4.4', 'WEB-4.3', 'F6', 'F6', 'WEB-4.7', 'WEB-4.5',
            'WEB-4.6', 'F5', 'F5', 'F3', 'F9',
        ], array_column($items, 'tarea'));
    }

    public function test_cada_item_dice_su_estado_y_solo_la_ficha_tiene_enlace(): void
    {
        $vm = $this->vista();

        $disponibles = [];
        foreach ($vm->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $disponibles[$item['titulo']] = $item;
            }
        }

        // Al cerrar WEB-4.1 el único ítem con pantalla es "Datos personales" (la ficha, F3).
        $this->assertSame(1, $vm->totalListos());
        $this->assertSame('/clinica/pacientes/5619', $disponibles['Datos personales']['url']);
        $this->assertSame(['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Disponible'],
            $disponibles['Datos personales']['estado']);

        // El resto no ofrece enlace y dice que llega después, con la tarea en el `title`.
        foreach ($disponibles as $titulo => $item) {
            if ($titulo === 'Datos personales') {
                continue;
            }

            $this->assertNull($item['url'], "{$titulo} no tiene que ofrecer un enlace todavía");
            $this->assertSame('Próximamente', $item['estado']['texto']);
            $this->assertSame('neutro', $item['estado']['tono']);
            $this->assertStringContainsString($item['tarea'], $item['titulo_estado']);
        }
    }

    public function test_el_estado_vacio_del_contenido_dice_que_las_secciones_llegan_despues(): void
    {
        $vacio = $this->vista()->sinContenido();

        $this->assertSame('bi-tools', $vacio['icono']);
        $this->assertStringContainsString('armazón', $vacio['texto']);
        $this->assertStringContainsString('13 ítems', $vacio['texto']);
    }

    public function test_las_urls_las_resuelve_el_controlador(): void
    {
        // Sin URLs (el ViewModel no llama a `route()`): el encabezado sigue armándose y los enlaces
        // quedan en `null` en vez de reventar.
        $vm = new ConsultaShellVista($this->paciente(), 1, '2026-10-05');

        $this->assertNull($vm->urlPaciente());
        $this->assertNull($vm->urlHistoria());
        $this->assertNull($vm->urlVolver());
        $this->assertSame('Pérez, Ana', $vm->nombre());
    }
}
