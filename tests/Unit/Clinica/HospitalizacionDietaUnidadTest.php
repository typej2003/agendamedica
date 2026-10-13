<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\Vistas\DietaVista;
use App\Clinica\Consulta\Vistas\HospitalizacionVista;
use App\Clinica\Impresion\DocumentoImpreso;
use PHPUnit\Framework\TestCase;

/**
 * **Hospitalización y dieta / recomendaciones** (WEB-5.3b) sin base de datos ni navegador: qué es un documento de estos dos tipos y qué
 * muestran sus pestañas. La escritura, la historia y la impresión están en los tests de `Feature`.
 */
class HospitalizacionDietaUnidadTest extends TestCase
{
    private const URLS = ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i'];

    // ===================================================================== Qué es un documento

    public function test_una_orden_es_un_documento_si_tiene_el_lugar_el_diagnostico_o_las_recomendaciones(): void
    {
        $this->assertFalse(QueEsUnDocumento::hospitalizacion(null, null, null), 'la fila vacía que crea el escritorio');
        $this->assertFalse(QueEsUnDocumento::hospitalizacion('', '   ', "\n"), 'las cadenas vacías');
        $this->assertFalse(QueEsUnDocumento::hospitalizacion('none', 'None', null), 'el literal none del DataWindow');
        $this->assertTrue(QueEsUnDocumento::hospitalizacion('CLINICA A', null, null));
        $this->assertTrue(QueEsUnDocumento::hospitalizacion(null, 'APENDICITIS', null));
        $this->assertTrue(QueEsUnDocumento::hospitalizacion('', '', 'Ayuno'));
    }

    public function test_una_dieta_es_un_documento_solo_si_tiene_texto_un_titulo_solo_no_lo_es(): void
    {
        $this->assertFalse(QueEsUnDocumento::dieta(null));
        $this->assertFalse(QueEsUnDocumento::dieta(''));
        $this->assertFalse(QueEsUnDocumento::dieta('  '));
        $this->assertFalse(QueEsUnDocumento::dieta('none'));
        $this->assertTrue(QueEsUnDocumento::dieta('Dieta blanda.'));
    }

    public function test_la_regla_de_los_otros_documentos_no_cambia(): void
    {
        $this->assertFalse(QueEsUnDocumento::reposo(1, null));
        $this->assertTrue(QueEsUnDocumento::reposo(2, null));
        $this->assertFalse(QueEsUnDocumento::informe('.'));
        $this->assertTrue(QueEsUnDocumento::informe('Texto'));
        $this->assertFalse(QueEsUnDocumento::constancia(null, ' ', null));
        $this->assertTrue(QueEsUnDocumento::constancia('Algo'));
    }

    public function test_las_claves_de_impresion_son_las_de_las_pestanas(): void
    {
        $this->assertSame('hospitalizacion', DocumentoImpreso::HOSPITALIZACION);
        $this->assertSame('dieta', DocumentoImpreso::DIETA);
    }

    // ================================================================================ Hospitalización

    public function test_la_pestana_de_hospitalizacion_muestra_lo_guardado_y_lo_escrito_manda_sobre_ello(): void
    {
        $vm = new HospitalizacionVista('CLINICA A', 'DX', 'REC', true, 'COLELITIASIS.', self::URLS);

        $this->assertSame('CLINICA A', $vm->hospital());
        $this->assertSame('DX', $vm->diagnostico());
        $this->assertSame('REC', $vm->recomendaciones());
        $this->assertTrue($vm->puedeEliminar());
        $this->assertSame('exito', $vm->estado()['tono']);
        $this->assertSame('Con orden', $vm->estado()['texto']);
        $this->assertTrue($vm->hayDiagnosticosDeLaConsulta());
        $this->assertSame(60, $vm->largoDelHospital());
        $this->assertSame('/i', $vm->urlImprimir());

        $conError = new HospitalizacionVista('CLINICA A', 'DX', 'REC', true, '', self::URLS, ['hospital' => 'LO ESCRITO', 'diagnostico' => '']);
        $this->assertSame('LO ESCRITO', $conError->hospital());
        $this->assertSame('', $conError->diagnostico(), 'lo escrito (aunque sea vacío) manda sobre lo guardado');
        $this->assertSame('REC', $conError->recomendaciones());
        $this->assertFalse($conError->hayDiagnosticosDeLaConsulta());
    }

    public function test_la_pestana_de_hospitalizacion_vacia_no_ofrece_eliminar_ni_dice_que_hay_orden(): void
    {
        $vm = new HospitalizacionVista(null, null, null, false, '', self::URLS);

        $this->assertSame('', $vm->hospital());
        $this->assertFalse($vm->puedeEliminar());
        $this->assertFalse($vm->esReal());
        $this->assertSame('neutro', $vm->estado()['tono']);
        $this->assertSame('Sin orden', $vm->estado()['texto']);
        $this->assertStringContainsString('cambios sin guardar', $vm->avisoSinGuardar());
        $this->assertStringContainsString('lugar', $vm->avisoEliminar());
    }

    // ============================================================================================ Dieta

    public function test_la_pestana_de_dieta_trae_las_plantillas_con_su_texto_y_su_titulo(): void
    {
        $plantillas = collect([
            (object) ['codigo' => 'DI01', 'titulo' => 'DIETA BLANDA', 'texto' => 'Caldos.'],
            (object) ['codigo' => 'DI02', 'titulo' => 'DIETA DIABÉTICA', 'texto' => 'Sin azúcar.'],
        ]);
        $vm = new DietaVista('TITULO', 'TEXTO', true, $plantillas, self::URLS);

        $this->assertSame('TITULO', $vm->titulo());
        $this->assertSame('TEXTO', $vm->texto());
        $this->assertTrue($vm->hayPlantillas());
        $this->assertSame([
            ['codigo' => 'DI01', 'titulo' => 'DIETA BLANDA', 'texto' => 'Caldos.'],
            ['codigo' => 'DI02', 'titulo' => 'DIETA DIABÉTICA', 'texto' => 'Sin azúcar.'],
        ], $vm->plantillas());
        $this->assertSame(200, $vm->largoDelTitulo());
        $this->assertSame('Con dieta', $vm->estado()['texto']);
    }

    public function test_la_pestana_de_dieta_vacia_y_con_errores(): void
    {
        $vacia = new DietaVista(null, null, false, collect(), self::URLS);
        $this->assertSame('', $vacia->titulo());
        $this->assertSame('', $vacia->texto());
        $this->assertFalse($vacia->hayPlantillas());
        $this->assertFalse($vacia->puedeEliminar());
        $this->assertSame('Sin dieta', $vacia->estado()['texto']);

        $conError = new DietaVista('GUARDADO', 'GUARDADO', true, collect(), self::URLS, ['titulo' => 'ESCRITO', 'descripcion' => 'ESCRITO TAMBIÉN']);
        $this->assertSame('ESCRITO', $conError->titulo());
        $this->assertSame('ESCRITO TAMBIÉN', $conError->texto());
    }
}
