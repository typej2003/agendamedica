<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Historia\Vistas\HistoriaDePacienteVista;
use App\Clinica\Historia\Vistas\ResumenDeHistoriaVista;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Los ViewModels de la **historia del paciente** (WEB-3.5) se prueban sin base de datos ni HTTP: datos de
 * entrada → textos de salida (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá: que el listado muestre los datos clave de cada consulta **recortados** (y la
 * impresión, completos), que las fechas basura del legado no rompan la pantalla, que los antecedentes se
 * agrupen por tipo con su marca de alerta —el único valor que la fuente del legado lee como alerta es
 * `tipo2 = 'A'`— y que cada bloque tenga su estado vacío.
 */
class HistoriaDePacienteVistaTest extends TestCase
{
    /** Una ficha como la devuelve `PacientesDelConsultorio::ficha` (con el número del pivote ya limpio). */
    private function ficha(array $campos = []): object
    {
        return (object) array_merge([
            'id'          => 5619,
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'cedula'      => '12345678',
            'numhistoria' => '1001',
        ], $campos);
    }

    private function consulta(array $campos = []): object
    {
        return (object) array_merge([
            'id'              => 1,
            'nroconsulta'     => 3,
            'fecha'           => '2026-03-10',
            'enfermedadactual' => 'Dolor pélvico de tres días de evolución',
            'evolucion'       => 'Mejoría con el tratamiento indicado',
            'motivos'         => ['DOLOR PELVICO'],
            'diagnosticos'    => ['ANEXITIS'],
            'resultadoexamencomp' => null,
            'faringe'         => null,
            'nariz'           => null,
            'oido'            => null,
            'laringe'         => null,
            'cuello'          => null,
            'otros'           => null,
        ], $campos);
    }

    private function antecedente(array $campos = []): object
    {
        return (object) array_merge([
            'codeantecedente' => '0001',
            'nombre'          => 'DIABETES',
            'detalle'         => 'Tipo 2, con tratamiento oral',
            'tipoCodigo'      => '01',
            'tipoNombre'      => 'Personales',
            'tipo2'           => null,
            'alerta'          => false,
        ], $campos);
    }

    private function documento(array $campos = []): array
    {
        return array_merge([
            'tipo'     => 'Reposo',
            'icono'    => 'bi-bandaid',
            'consulta' => 3,
            'fecha'    => '2026-03-10',
            'resumen'  => '3 días — Reposo absoluto',
        ], $campos);
    }

    /** @param  array<int, object>  $consultas */
    private function vista(
        array $consultas = [],
        array $antecedentes = [],
        array $documentos = [],
        ?object $ficha = null,
        ?string $urlImprimir = '/clinica/pacientes/5619/historia/imprimir'
    ): HistoriaDePacienteVista {
        return new HistoriaDePacienteVista(
            $ficha ?? $this->ficha(),
            collect($consultas),
            collect($antecedentes),
            collect($documentos),
            '/clinica/pacientes/5619',
            $urlImprimir,
        );
    }

    // ------------------------------------------------------------------------------------ Consultas

    public function test_las_consultas_se_listan_con_sus_datos_clave(): void
    {
        $vista = $this->vista([$this->consulta()]);
        $fila = $vista->consultas()[0];

        $this->assertSame('10/03/2026', $fila['fecha']);
        $this->assertSame('Consulta 3', $fila['numero']);
        $this->assertSame(['DOLOR PELVICO'], $fila['motivos']);
        $this->assertSame('Dolor pélvico de tres días de evolución', $fila['enfermedad']);
        $this->assertSame('ANEXITIS', $fila['diagnostico']);
        $this->assertSame('Mejoría con el tratamiento indicado', $fila['evolucion']);
        $this->assertSame(1, $vista->totalConsultas());
    }

    public function test_los_varios_diagnosticos_se_muestran_en_una_linea(): void
    {
        $vista = $this->vista([$this->consulta(['diagnosticos' => ['ANEXITIS', 'QUISTE DE OVARIO']])]);

        $this->assertSame('ANEXITIS · QUISTE DE OVARIO', $vista->consultas()[0]['diagnostico']);
    }

    /**
     * El listado no es el documento: lo que no entra se recorta con `…`. La impresión, en cambio, muestra
     * el texto completo (`ResumenDeHistoriaVista`).
     */
    public function test_el_texto_largo_se_recorta_para_el_listado(): void
    {
        $vista = $this->vista([$this->consulta(['enfermedadactual' => str_repeat('dolor ', 60)])]);
        $recortado = $vista->consultas()[0]['enfermedad'];

        $this->assertStringEndsWith('…', $recortado);
        $this->assertLessThanOrEqual(161, mb_strlen($recortado));
    }

    /** Las fechas nulas del legado (`0000-00-00`, `NULL`, basura) no rompen la fila: se muestran como `—`. */
    public function test_una_fecha_basura_no_rompe_la_fila(): void
    {
        $vista = $this->vista([
            $this->consulta(['fecha' => '0000-00-00']),
            $this->consulta(['fecha' => null, 'nroconsulta' => 2]),
        ]);

        $this->assertSame('—', $vista->consultas()[0]['fecha']);
        $this->assertSame('—', $vista->consultas()[1]['fecha']);
    }

    // --------------------------------------------------------------------------------- Antecedentes

    public function test_los_antecedentes_se_agrupan_por_tipo_y_marcan_la_alerta(): void
    {
        $vista = $this->vista([], [
            $this->antecedente(['nombre' => 'DIABETES', 'alerta' => true, 'tipo2' => 'A']),
            $this->antecedente(['nombre' => 'HIPERTENSION', 'codeantecedente' => '0002']),
            $this->antecedente([
                'nombre'     => 'ANTECEDENTE SUELTO',
                'tipoNombre' => null,
                'tipoCodigo' => null,
            ]),
        ]);

        $grupos = $vista->antecedentes();

        $this->assertSame(3, $vista->totalAntecedentes());
        $this->assertSame('Personales', $grupos[0]['titulo']);
        $this->assertCount(2, $grupos[0]['filas']);
        $this->assertTrue($grupos[0]['filas'][0]['alerta']);
        $this->assertFalse($grupos[0]['filas'][1]['alerta']);
        // Un tipo que el catálogo no resuelve no se inventa: va al grupo "Sin clasificar", al final.
        $this->assertSame('Sin clasificar', $grupos[1]['titulo']);
        $this->assertSame('ANTECEDENTE SUELTO', $grupos[1]['filas'][0]['nombre']);
        $this->assertNotSame('', $grupos[0]['filas'][0]['alertaTitulo']);
    }

    public function test_dos_tipos_distintos_arman_dos_grupos_en_orden(): void
    {
        $vista = $this->vista([], [
            $this->antecedente(['tipoNombre' => 'Familiares', 'tipoCodigo' => '02']),
            $this->antecedente(['tipoNombre' => 'Personales', 'tipoCodigo' => '01']),
        ]);

        $this->assertSame(['Familiares', 'Personales'], array_column($vista->antecedentes(), 'titulo'));
    }

    // ----------------------------------------------------------------------------------- Documentos

    public function test_los_documentos_se_listan_con_su_tipo_su_consulta_y_su_resumen(): void
    {
        $vista = $this->vista([], [], [
            $this->documento(),
            $this->documento(['tipo' => 'Informe médico', 'icono' => 'bi-file-earmark-text', 'fecha' => null, 'resumen' => null]),
        ]);

        $filas = $vista->documentos();

        $this->assertSame(2, $vista->totalDocumentos());
        $this->assertSame('Reposo', $filas[0]['tipo']);
        $this->assertSame('Consulta 3', $filas[0]['consulta']);
        $this->assertSame('10/03/2026', $filas[0]['fecha']);
        $this->assertSame('3 días — Reposo absoluto', $filas[0]['resumen']);
        // Un documento sin fecha propia cae a `—` (no a la fecha de hoy ni a un texto vacío).
        $this->assertSame('—', $filas[1]['fecha']);
        $this->assertNull($filas[1]['resumen']);
    }

    // ------------------------------------------------------------------------------------- Estados

    public function test_sin_historia_muestra_el_estado_vacio_y_no_ofrece_imprimir(): void
    {
        $vista = $this->vista([], [], [], $this->ficha(['numhistoria' => null]));

        $this->assertFalse($vista->tieneHistoria());
        $this->assertFalse($vista->puedeImprimir());
        $this->assertSame('Todavía sin número de historia', $vista->subtitulo());
        $this->assertStringContainsString('no hay consultas, antecedentes ni documentos', $vista->sinHistoria()['texto']);
        $this->assertSame('/clinica/pacientes/5619', $vista->urlFicha());
    }

    /** El `numhistoria` vacío del pivote es "sin historia", no "historia 0" (`CamposDePaciente`). */
    public function test_un_numhistoria_vacio_es_sin_historia(): void
    {
        $vista = $this->vista([], [], [], $this->ficha(['numhistoria' => '  ']));

        $this->assertFalse($vista->tieneHistoria());
        $this->assertNull($vista->numeroDeHistoria());
    }

    public function test_con_historia_sin_datos_cada_bloque_tiene_su_estado_vacio(): void
    {
        $vista = $this->vista();

        $this->assertTrue($vista->tieneHistoria());
        $this->assertSame('Historia N.º 1001 · 0 consultas', $vista->subtitulo());
        $this->assertSame(0, $vista->totalConsultas());
        $this->assertSame(0, $vista->totalAntecedentes());
        $this->assertSame(0, $vista->totalDocumentos());
        $this->assertNotSame('', $vista->sinConsultas()['titulo']);
        $this->assertNotSame('', $vista->sinAntecedentes()['titulo']);
        $this->assertNotSame('', $vista->sinDocumentos()['titulo']);
        // Sin consultas no hay resumen que imprimir, aunque el paciente tenga historia.
        $this->assertFalse($vista->puedeImprimir());
    }

    public function test_el_subtitulo_concuerda_en_numero(): void
    {
        $this->assertSame('Historia N.º 1001 · 1 consulta', $this->vista([$this->consulta()])->subtitulo());
        $this->assertSame(
            'Historia N.º 1001 · 2 consultas',
            $this->vista([$this->consulta(), $this->consulta(['nroconsulta' => 2])])->subtitulo()
        );
    }

    public function test_sin_url_de_impresion_no_se_ofrece_imprimir(): void
    {
        $vista = $this->vista([$this->consulta()], [], [], null, null);

        $this->assertTrue($vista->tieneHistoria());
        $this->assertFalse($vista->puedeImprimir());
        $this->assertNull($vista->urlImprimir());
    }

    // ---------------------------------------------------------------------------- Resumen (impresión)

    private function resumen(array $consultas = [], ?object $ficha = null): ResumenDeHistoriaVista
    {
        return new ResumenDeHistoriaVista(
            $ficha ?? $this->ficha(),
            'Dr. Carlos Mendoza',
            collect($consultas),
            Carbon::parse('2026-10-10'),
        );
    }

    public function test_el_resumen_lleva_el_encabezado_y_las_consultas_completas(): void
    {
        $vista = $this->resumen([$this->consulta(['evolucion' => str_repeat('evoluciona bien ', 40)])]);

        $this->assertSame('Resumen de historia clínica', $vista->titulo());
        $this->assertSame('Dr. Carlos Mendoza', $vista->medico());
        $this->assertSame('Pérez, Ana', $vista->paciente());
        $this->assertSame('12345678', $vista->cedula());
        $this->assertSame('H. 1001', $vista->historia());
        $this->assertSame('10/10/2026', $vista->fecha());
        $this->assertTrue($vista->tieneConsultas());

        $consulta = $vista->consultas()[0];
        $this->assertSame('10/03/2026', $consulta['fecha']);
        $this->assertSame('Consulta 3', $consulta['numero']);
        $this->assertSame(
            ['Motivo de consulta', 'Enfermedad actual', 'Diagnóstico', 'Evolución'],
            array_column($consulta['secciones'], 'titulo')
        );
        // La impresión no recorta: es el documento, no el listado.
        $evolucion = collect($consulta['secciones'])->firstWhere('titulo', 'Evolución');
        $this->assertStringNotContainsString('…', $evolucion['texto']);
        $this->assertGreaterThan(161, mb_strlen($evolucion['texto']));
    }

    public function test_el_resumen_no_imprime_las_secciones_vacias(): void
    {
        $vista = $this->resumen([$this->consulta([
            'enfermedadactual' => null,
            'evolucion'        => null,
            'motivos'          => [],
            'diagnosticos'     => ['ANEXITIS'],
        ])]);

        $this->assertSame(['Diagnóstico'], array_column($vista->consultas()[0]['secciones'], 'titulo'));
    }

    public function test_el_resumen_arma_el_examen_fisico_con_lo_que_haya(): void
    {
        $vista = $this->resumen([$this->consulta([
            'resultadoexamencomp' => 'Abdomen blando',
            'faringe'             => 'Normal',
            'nariz'               => null,
        ])]);

        $examen = collect($vista->consultas()[0]['secciones'])->firstWhere('titulo', 'Examen físico');
        $this->assertStringContainsString('Resultado del examen: Abdomen blando', $examen['texto']);
        $this->assertStringContainsString('Faringe: Normal', $examen['texto']);
        $this->assertStringNotContainsString('Nariz', $examen['texto']);
    }

    public function test_el_resumen_sin_consultas_lo_dice(): void
    {
        $vista = $this->resumen();

        $this->assertFalse($vista->tieneConsultas());
        $this->assertSame([], $vista->consultas());
    }

    public function test_el_resumen_de_un_paciente_sin_cedula_ni_historia_lo_dice(): void
    {
        $vista = $this->resumen([], $this->ficha(['cedula' => 'S.C.', 'numhistoria' => null]));

        $this->assertSame('Sin cédula', $vista->cedula());
        $this->assertSame('Sin número de historia', $vista->historia());
    }

    public function test_la_consulta_lleva_enlace_solo_si_quien_mira_puede_abrirla(): void
    {
        $sin = $this->vista([$this->consulta()]);
        $this->assertNull($sin->consultas()[0]['url']);

        $con = new HistoriaDePacienteVista(
            $this->ficha(),
            collect([$this->consulta(['id' => 77])]),
            collect(),
            collect(),
            '/clinica/pacientes/5619',
            null,
            null,
            null,
            fn (int $id) => "/clinica/consulta/{$id}",
        );
        $this->assertSame('/clinica/consulta/77', $con->consultas()[0]['url']);
    }
}
