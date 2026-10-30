<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel del **shell de la consulta** (WEB-4.1, ampliado en WEB-4.2) se prueba sin base de datos ni
 * HTTP: datos de entrada → textos de salida (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá: el encabezado (paciente, consulta, historia y fecha), que el panel tenga los
 * **13 ítems del legado** (O-22 retiró *Exámenes*) en su orden y con su tarea, **qué ítems están disponibles
 * y a dónde lleva cada uno** —los de WEB-4.2 abren su sección dentro de la pantalla (`?seccion=`), *Datos
 * personales* enlaza a la ficha— y que lo que todavía no existe diga "Próximamente" en vez de ofrecer un
 * enlace muerto.
 */
class ConsultaShellVistaTest extends TestCase
{
    private function paciente(array $campos = [], ?string $fnacimiento = null): object
    {
        $base = [
            'id'          => 5619,
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'numhistoria' => '1001',
        ];

        if ($fnacimiento !== null) {
            $base['fnacimiento'] = $fnacimiento;
        }

        return (object) array_merge($base, $campos);
    }

    private function vista(array $extra = []): ConsultaShellVista
    {
        return new ConsultaShellVista(
            $extra['paciente'] ?? $this->paciente([], $extra['fnacimiento'] ?? null),
            $extra['nroconsulta'] ?? 3,
            $extra['fecha'] ?? '2026-10-05',
            $extra['urlPaciente'] ?? '/clinica/pacientes/5619',
            $extra['urlHistoria'] ?? '/clinica/pacientes/5619/historia',
            $extra['urlVolver'] ?? '/clinica/agenda?vista=dia&fecha=2026-10-05&sede=todas',
            $extra['urlConsulta'] ?? '/clinica/consulta/42',
            $extra['seccion'] ?? null,
            // De acá abajo, los que la pantalla no usa en estas pruebas: van con su valor por defecto para llegar a
            // la especialidad, que es el 15.º argumento y el que decide si el encabezado lleva la edad.
            $extra['urlTerminar'] ?? null,
            $extra['terminable'] ?? false,
            $extra['consultasDelPaciente'] ?? [],
            $extra['consultaActual'] ?? null,
            $extra['alertas'] ?? [],
            $extra['avisarAlertas'] ?? false,
            $extra['especialidad'] ?? null,
        );
    }

    public function test_el_encabezado_lleva_el_paciente_la_consulta_la_historia_la_fecha_y_la_edad(): void
    {
        $vm = $this->vista(['fnacimiento' => '1990-05-10']);

        $this->assertSame('Pérez, Ana', $vm->nombre());
        $this->assertSame('H. 1001', $vm->historia());
        // A-11: la fila es `NOMBRE · edad · H. n.º · Consulta n.º · fecha`; la edad va siempre (Alexander, 2026-10-29).
        $this->assertSame('36 años · H. 1001 · Consulta N.º 3 · 05/10/2026', $vm->subtitulo());
        $this->assertSame('H. 1001 · Consulta N.º 3 · 05/10/2026', $vm->detalleDeLaCabecera());
        $this->assertSame('Pérez, Ana · 36 años · H. 1001 · Consulta N.º 3 · 05/10/2026', $vm->lineaDeCabecera());
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
        $this->assertSame('sin fecha de nacimiento · Consulta N.º 3 · —', $vm->subtitulo());
        $this->assertSame('Paciente sin nombre · sin fecha de nacimiento · Consulta N.º 3 · —', $vm->lineaDeCabecera());
    }

    public function test_la_edad_abre_la_linea_del_encabezado_en_todas_las_especialidades(): void
    {
        // Alexander, 2026-10-29: la edad se ve arriba, en la barra, en todo momento dentro de la consulta. A los
        // menores de 6 años se dice con meses y días; a los adultos, en años cumplidos.
        foreach ([null, 'ginecologia-y-obstetricia', 'medicina-interna', 'pediatria'] as $slug) {
            $vm = $this->vista(['especialidad' => $slug, 'fnacimiento' => '2024-07-02']);

            $this->assertSame('2 años 3 meses 3 días', $vm->edad(), (string) $slug);
            $this->assertSame(
                '2 años 3 meses 3 días · H. 1001 · Consulta N.º 3 · 05/10/2026',
                $vm->subtitulo(),
                (string) $slug,
            );
            $this->assertSame(
                'Pérez, Ana · 2 años 3 meses 3 días · H. 1001 · Consulta N.º 3 · 05/10/2026',
                $vm->lineaDeCabecera(),
                (string) $slug,
            );
        }

        $adulto = $this->vista(['especialidad' => 'medicina-interna', 'fnacimiento' => '1990-05-10']);
        $this->assertSame('36 años', $adulto->edad());
        $this->assertSame('36 años · H. 1001 · Consulta N.º 3 · 05/10/2026', $adulto->subtitulo());
    }

    public function test_la_edad_se_cuenta_a_la_fecha_de_la_consulta_no_a_hoy(): void
    {
        // El mismo paciente en dos consultas: la edad es la que tenía **ese día**, no la de hoy.
        $rn = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '2024-07-02', 'fecha' => '2024-07-07']);
        $this->assertSame('5 días', $rn->edad());
        $this->assertSame('5 días · H. 1001 · Consulta N.º 3 · 07/07/2024', $rn->subtitulo());

        $meses = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '2024-07-02', 'fecha' => '2024-10-14']);
        $this->assertSame('3 meses 12 días', $meses->edad());

        $anios = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '2024-07-02', 'fecha' => '2026-10-05']);
        $this->assertSame('2 años 3 meses 3 días', $anios->edad());

        $sinDias = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '2018-10-05', 'fecha' => '2026-10-05']);
        $this->assertSame('8 años', $sinDias->edad());
    }

    public function test_sin_fecha_de_nacimiento_el_aviso_aparece_igual_en_cualquier_especialidad(): void
    {
        // Nunca desaparece en silencio: sin el dato (o con la basura del legado) se dice que falta, en el mismo
        // lugar donde iría la edad.
        foreach ([null, 'ginecologia-y-obstetricia', 'medicina-interna', 'pediatria'] as $slug) {
            $sinDato = $this->vista(['especialidad' => $slug]);
            $this->assertSame('sin fecha de nacimiento', $sinDato->edad(), (string) $slug);
            $this->assertSame(
                'sin fecha de nacimiento · H. 1001 · Consulta N.º 3 · 05/10/2026',
                $sinDato->subtitulo(),
                (string) $slug,
            );
            $this->assertSame(
                'Pérez, Ana · sin fecha de nacimiento · H. 1001 · Consulta N.º 3 · 05/10/2026',
                $sinDato->lineaDeCabecera(),
                (string) $slug,
            );
        }

        $nula = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '0000-00-00']);
        $this->assertSame('sin fecha de nacimiento', $nula->edad());
        $this->assertStringStartsWith('sin fecha de nacimiento · H. 1001 · ', $nula->subtitulo());

        // Una consulta anterior al nacimiento tampoco puede inventar una edad.
        $anterior = $this->vista(['especialidad' => 'pediatria', 'fnacimiento' => '2024-07-02', 'fecha' => '2024-07-01']);
        $this->assertSame('sin fecha de nacimiento', $anterior->edad());
        $this->assertStringStartsWith('sin fecha de nacimiento · H. 1001 · ', $anterior->subtitulo());
    }

    public function test_en_un_adulto_la_edad_va_solo_en_anios_cumplidos(): void
    {
        // Alexander, 2026-10-29: la edad en la barra también fuera de pediatría; en un adulto los meses y los días
        // no aportan y se muestran sólo los años, contados a la fecha de la consulta.
        $vm = $this->vista(['fnacimiento' => '1980-02-29', 'fecha' => '2026-10-05']);

        $this->assertSame('46 años', $vm->edad());
        $this->assertSame('46 años · H. 1001 · Consulta N.º 3 · 05/10/2026', $vm->subtitulo());
    }

    public function test_el_panel_tiene_los_trece_items_en_su_orden_y_con_su_tarea(): void
    {
        // El panel del legado es el de ginecología: sin especialidad (2026-10-30) el shell muestra el núcleo de once.
        $vm = $this->vista(['especialidad' => 'ginecologia-y-obstetricia']);
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

        // Los títulos, en el orden del panel de `w_consulta_principal` (wiki 04-historia-clinica.md), sin
        // *Exámenes* (O-22: su galería pasó a Evaluación física y sus exámenes a Resultado de exámenes).
        $this->assertSame([
            'Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física',
            'Estudio ecográfico', 'Control de embarazo', 'Diagnóstico', 'Evolución',
            'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura',
        ], array_column($items, 'titulo'));

        // Y cada uno con la tarea que lo completa: es el mapa del shell, no decoración.
        $this->assertSame([
            'WEB-4.2', 'WEB-4.2', 'WEB-4.4', 'WEB-4.3', 'WEB-6.1', 'WEB-6.6', 'WEB-4.5',
            'WEB-4.6', 'WEB-5.1', 'WEB-5.7', 'WEB-5.3a', 'WEB-11.25', 'F9',
        ], array_column($items, 'tarea'));
    }

    public function test_el_panel_marca_disponibles_los_items_de_la_consulta_y_la_ficha(): void
    {
        $vm = $this->vista(['especialidad' => 'ginecologia-y-obstetricia']);

        $disponibles = [];
        foreach ($vm->panel() as $bloque) {
            foreach ($bloque['items'] as $item) {
                $disponibles[$item['titulo']] = $item;
            }
        }

        // Desde F9 los **trece** ítems tienen pantalla: motivos, enfermedad actual, antecedentes, evaluación
        // física, estudio ecográfico, control de embarazo, diagnóstico, evolución, récipes, tratamiento,
        // solicitudes, datos personales y la pre-factura, todos **dentro** del shell.
        $this->assertSame(13, $vm->totalListos());

        $this->assertSame('/clinica/consulta/42?seccion=motivos', $disponibles['Motivos de consulta']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=enfermedad', $disponibles['Enfermedad actual']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=antecedentes', $disponibles['Antecedentes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=evaluacion', $disponibles['Evaluación física']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=estudio', $disponibles['Estudio ecográfico']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=diagnostico', $disponibles['Diagnóstico']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=evolucion', $disponibles['Evolución']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=recipes', $disponibles['Récipes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=tratamiento', $disponibles['Tratamiento']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=solicitudes', $disponibles['Solicitudes']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=datos', $disponibles['Datos personales']['url']);
        $this->assertSame('/clinica/consulta/42?seccion=prefactura', $disponibles['Pre-factura']['url']);

        // La sección *Exámenes* ya no existe (O-22): ni ítem ni enlace.
        $this->assertArrayNotHasKey('Exámenes', $disponibles);

        foreach (['Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física', 'Diagnóstico', 'Evolución', 'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales'] as $titulo) {
            // Los disponibles no llevan pastilla: sólo "Próximamente" distingue a los que faltan.
            $this->assertNull($disponibles[$titulo]['estado'], $titulo);
            $this->assertNull($disponibles[$titulo]['titulo_estado'], $titulo);
        }

        // El resto no ofrece enlace y dice que llega después, con la tarea en el `title`.
        foreach ($disponibles as $titulo => $item) {
            if (in_array($titulo, ['Motivos de consulta', 'Enfermedad actual', 'Antecedentes', 'Evaluación física', 'Estudio ecográfico', 'Control de embarazo', 'Diagnóstico', 'Evolución', 'Récipes', 'Tratamiento', 'Solicitudes', 'Datos personales', 'Pre-factura'], true)) {
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
        $this->assertFalse($items['Evolución']['activo']);

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

        // WEB-5.1: los récipes son la séptima sección que se abre dentro del shell.
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
        $this->assertFalse($items['Evolución']['activo']);
    }

    public function test_una_seccion_que_no_existe_es_como_no_haber_abierto_ninguna(): void
    {
        // Un valor inventado en la URL no abre una sección que no exista (y la vista vuelve al estado
        // vacío del shell en vez de romperse).
        $vm = $this->vista(['seccion' => 'no-existe']);

        $this->assertNull($vm->seccion());
        $this->assertNull($vm->parcial());

        // Sin la URL de la consulta, los ítems que abren sección no ofrecen un enlace muerto.
        $sinUrl = new ConsultaShellVista($this->paciente(), 3, '2026-10-05', null, null, null, null, ConsultaShellVista::SECCION_MOTIVOS);
        $items = $sinUrl->panel()[0]['items'];
        $this->assertNull($items[0]['url']);
    }

    public function test_el_estado_vacio_del_contenido_manda_al_menu_lateral(): void
    {
        $vacio = $this->vista()->sinContenido();

        $this->assertSame('bi-list-ul', $vacio['icono']);
        $this->assertStringContainsString('menú', $vacio['titulo'] . ' ' . $vacio['texto']);
        $this->assertStringContainsString('motivos de consulta', $vacio['texto']);
        // El panel de la pantalla ya no existe: el texto no puede mandar a un lugar que no está.
        $this->assertStringNotContainsString('panel', $vacio['texto']);
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
