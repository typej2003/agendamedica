<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel del **shell de la consulta** (WEB-4.1, ampliado en WEB-4.2) se prueba sin base de datos ni
 * HTTP: datos de entrada → textos de salida (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá: el encabezado (paciente, consulta, historia y fecha), que el panel tenga los
 * **13 ítems del legado** en su orden y con su tarea, **qué ítems están disponibles y a dónde lleva
 * cada uno** —los de WEB-4.2 abren su sección dentro de la pantalla (`?seccion=`), *Datos personales*
 * enlaza a la ficha— y que lo que todavía no existe diga "Próximamente" en vez de ofrecer un enlace
 * muerto.
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
            $extra['urlConsulta'] ?? '/clinica/consulta/42',
            $extra['seccion'] ?? null,
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
            'WEB-4.2', 'WEB-4.2', 'WEB-4.4', 'WEB-4.3', 'WEB-6.1', 'WEB-6.6', 'WEB-4.7', 'WEB-4.5',
            'WEB-4.6', 'WEB-5.1', 'WEB-5.3a', 'F3', 'F9',
        ], array_column($items, 'tarea'));
    }

    public function test_el_panel_marca_disponibles_los_items_de_la_consulta_y_la_ficha(): void
    {
        $vm = $this->vista();

        $disponibles = [];
        foreach ($vm->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $disponibles[$item['titulo']] = $item;
            }
        }

        // Con WEB-6.6 ya hay **doce** ítems con pantalla: motivos, enfermedad actual, antecedentes,
        // evaluación física, estudio ecográfico, control de embarazo, exámenes, diagnóstico, evolución,
        // récipes y solicitudes, que se abren **dentro** del shell, y Datos personales, que enlaza a la
        // ficha (F3).
        $this->assertSame(12, $vm->totalListos());

        $this->assertSame('/clinica/consulta/42?seccion=motivos', $disponibles['Motivos de consulta']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=enfermedad', $disponibles['Enfermedad actual']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=antecedentes', $disponibles['Antecedentes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=evaluacion', $disponibles['Evaluación física']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=estudio', $disponibles['Estudio ecográfico']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=examenes', $disponibles['Exámenes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=diagnostico', $disponibles['Diagnóstico']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=evolucion', $disponibles['Evolución']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=recipes', $disponibles['Récipes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=solicitudes', $disponibles['Solicitudes']['url']);
        $this->assertSame('/clinica/pacientes/5619', $disponibles['Datos personales']['url']);

        foreach (['Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física', 'Exámenes', 'Diagnóstico', 'Evolución', 'Récipes', 'Solicitudes', 'Datos personales'] as $titulo) {
            // Los disponibles no llevan pastilla: sólo "Próximamente" distingue a los que faltan.
            $this->assertNull($disponibles[$titulo]['estado'], $titulo);
            $this->assertNull($disponibles[$titulo]['titulo_estado'], $titulo);
        }

        // El resto no ofrece enlace y dice que llega después, con la tarea en el `title`.
        foreach ($disponibles as $titulo => $item) {
            if (in_array($titulo, ['Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física', 'Estudio ecográfico', 'Control de embarazo', 'Exámenes', 'Diagnóstico', 'Evolución', 'Récipes', 'Solicitudes', 'Datos personales'], true)) {
                continue;
            }

            $this->assertNull($item['url'], "{$titulo} no tiene que ofrecer un enlace todavía");
            $this->assertSame('Próximamente', $item['estado']['texto']);
            $this->assertSame('neutro', $item['estado']['tono']);
            $this->assertStringContainsString($item['tarea'], $item['titulo_estado']);
        }
    }

    public function test_la_seccion_activa_marca_su_item_y_resuelve_el_parcial(): void
    {
        $conMotivos = $this->vista(['seccion' => ConsultaShellVista::SECCION_MOTIVOS]);

        $this->assertSame('motivos', $conMotivos->seccion());
        $this->assertSame('clinica.consulta._motivos', $conMotivos->parcial());

        $items = [];
        foreach ($conMotivos->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Motivos de consulta']['activo']);
        $this->assertFalse($items['Enfermedad actual']['activo']);

        $conEnfermedad = $this->vista(['seccion' => ConsultaShellVista::SECCION_ENFERMEDAD]);
        $this->assertSame('clinica.consulta._enfermedad', $conEnfermedad->parcial());

        // WEB-4.3: la evaluación física es la tercera sección que se abre dentro del shell.
        $conEvaluacion = $this->vista(['seccion' => ConsultaShellVista::SECCION_EVALUACION]);
        $this->assertSame('evaluacion', $conEvaluacion->seccion());
        $this->assertSame('clinica.consulta._evaluacion', $conEvaluacion->parcial());

        $items = [];
        foreach ($conEvaluacion->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Evaluación física']['activo']);
        $this->assertFalse($items['Antecedentes']['activo']);

        // WEB-4.4: los antecedentes son la cuarta sección que se abre dentro del shell.
        $conAntecedentes = $this->vista(['seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]);
        $this->assertSame('antecedentes', $conAntecedentes->seccion());
        $this->assertSame('clinica.consulta._antecedentes', $conAntecedentes->parcial());

        $items = [];
        foreach ($conAntecedentes->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Antecedentes']['activo']);
        $this->assertFalse($items['Evaluación física']['activo']);

        // WEB-4.5: los diagnósticos son la quinta sección que se abre dentro del shell.
        $conDiagnostico = $this->vista(['seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO]);
        $this->assertSame('diagnostico', $conDiagnostico->seccion());
        $this->assertSame('clinica.consulta._diagnostico', $conDiagnostico->parcial());

        $items = [];
        foreach ($conDiagnostico->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Diagnóstico']['activo']);
        $this->assertFalse($items['Exámenes']['activo']);

        // WEB-4.6: la evolución es la sexta sección que se abre dentro del shell.
        $conEvolucion = $this->vista(['seccion' => ConsultaShellVista::SECCION_EVOLUCION]);
        $this->assertSame('evolucion', $conEvolucion->seccion());
        $this->assertSame('clinica.consulta._evolucion', $conEvolucion->parcial());

        $items = [];
        foreach ($conEvolucion->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Evolución']['activo']);
        $this->assertFalse($items['Récipes']['activo']);

        // WEB-4.7: los exámenes son la séptima sección que se abre dentro del shell.
        $conExamenes = $this->vista(['seccion' => ConsultaShellVista::SECCION_EXAMENES]);
        $this->assertSame('examenes', $conExamenes->seccion());
        $this->assertSame('clinica.consulta._examenes', $conExamenes->parcial());

        $items = [];
        foreach ($conExamenes->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Exámenes']['activo']);
        $this->assertFalse($items['Diagnóstico']['activo']);

        // WEB-5.1: los récipes son la octava sección que se abre dentro del shell.
        $conRecipes = $this->vista(['seccion' => ConsultaShellVista::SECCION_RECIPES]);
        $this->assertSame('recipes', $conRecipes->seccion());
        $this->assertSame('clinica.consulta._recipes', $conRecipes->parcial());

        $items = [];
        foreach ($conRecipes->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $items[$item['titulo']] = $item;
            }
        }

        $this->assertTrue($items['Récipes']['activo']);
        $this->assertFalse($items['Exámenes']['activo']);
    }

    public function test_una_seccion_que_no_existe_es_como_no_haber_abierto_ninguna(): void
    {
        // Un valor inventado en la URL no abre una sección que no exista (y la vista vuelve al estado
        // vacío del shell en vez de romperse).
        $vm = $this->vista(['seccion' => 'prefactura']);

        $this->assertNull($vm->seccion());
        $this->assertNull($vm->parcial());

        // Sin la URL de la consulta, los ítems que abren sección no ofrecen un enlace muerto.
        $sinUrl = new ConsultaShellVista($this->paciente(), 3, '2026-10-05', null, null, null, null, ConsultaShellVista::SECCION_MOTIVOS);
        $items = $sinUrl->panel()[0]['items'];
        $this->assertNull($items[0]['url']);
    }

    public function test_el_estado_vacio_del_contenido_dice_que_las_secciones_se_abren_del_panel(): void
    {
        $vacio = $this->vista()->sinContenido();

        $this->assertSame('bi-list-ul', $vacio['icono']);
        $this->assertStringContainsString('panel', $vacio['texto']);
        $this->assertStringContainsString('motivos de consulta', $vacio['texto']);
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
