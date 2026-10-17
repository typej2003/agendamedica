<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use PHPUnit\Framework\TestCase;

/**
 * WEB-12.1: qué partes de «Atender» aplican a cada especialidad. Lo que se fija es que **ginecología no cambia** y que la pediatría
 * pierde sólo lo ginecológico.
 */
class AlcanceDeLaEspecialidadTest extends TestCase
{
    private const GINECO = 'ginecologia-y-obstetricia';

    private function shell(?string $especialidad): ConsultaShellVista
    {
        return new ConsultaShellVista(
            (object) ['id' => 1, 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'numhistoria' => '1001'],
            1, '2026-10-05', '/p', '/h', '/v', '/clinica/consulta/42', null, null, false, [], null, [], false, $especialidad,
        );
    }

    private function numeros(ConsultaShellVista $vm): array
    {
        return array_merge(...array_map(
            fn (array $bloque) => array_column($bloque['items'], 'numero'),
            $vm->panel(),
        ));
    }

    public function test_ginecologia_y_sin_especialidad_conservan_el_panel_de_catorce_items(): void
    {
        foreach ([self::GINECO, null, 'cirugia'] as $slug) {
            $vm = $this->shell($slug);
            $this->assertSame(range(1, 14), $this->numeros($vm), (string) $slug);
            $this->assertSame(14, $vm->totalItems());
        }
    }

    public function test_pediatria_pierde_el_control_de_embarazo_y_conserva_la_numeracion(): void
    {
        $vm = $this->shell('pediatria');

        $this->assertSame([1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14], $this->numeros($vm));
        $this->assertSame(13, $vm->totalItems());
        $this->assertSame(12, $vm->totalListos());
    }

    public function test_una_seccion_oculta_no_se_abre_en_pediatria(): void
    {
        $vm = new ConsultaShellVista(
            (object) ['id' => 1, 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'numhistoria' => '1001'],
            1, '2026-10-05', '/p', '/h', '/v', '/clinica/consulta/42', ConsultaShellVista::SECCION_EMBARAZO, null, false, [], null, [], false, 'pediatria',
        );

        $this->assertNull($vm->seccion());
        $this->assertNull($vm->parcial());
    }

    public function test_el_estudio_ecografico_en_pediatria_es_solo_la_eco_abdominal(): void
    {
        $alcance = AlcanceDeLaEspecialidad::de('pediatria');

        $this->assertNotContains(TiposDeEstudio::ABDOMINAL, $alcance->tiposDeEstudioOcultos());
        $this->assertSame(TiposDeEstudio::ABDOMINAL, $alcance->tipoDeEstudio(''));
        // La URL directa sigue abriendo lo que no se ofrece como chip.
        $this->assertSame(TiposDeEstudio::OBSTETRICO, $alcance->tipoDeEstudio(TiposDeEstudio::OBSTETRICO));
    }

    public function test_el_estudio_en_ginecologia_sigue_como_antes(): void
    {
        $alcance = AlcanceDeLaEspecialidad::de(self::GINECO);

        $this->assertSame([TiposDeEstudio::ABDOMINAL], $alcance->tiposDeEstudioOcultos());
        $this->assertSame(TiposDeEstudio::GINECOLOGICO, $alcance->tipoDeEstudio(''));
        $this->assertSame(TiposDeEstudio::OBSTETRICO, $alcance->tipoDeEstudio(TiposDeEstudio::OBSTETRICO));
        $this->assertSame([], AlcanceDeLaEspecialidad::de('cirugia')->tiposDeEstudioOcultos());
    }

    public function test_las_solapas_del_examen_en_pediatria_son_solo_el_examen_fisico(): void
    {
        $pediatria = AlcanceDeLaEspecialidad::de('pediatria');
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $pediatria->pestanasDelExamenOcultas());

        $this->assertSame([PestanasDelExamen::FISICO], array_column($pestanas->opciones(PestanasDelExamen::FISICO), 'clave'));
        $this->assertSame(PestanasDelExamen::FISICO, $pediatria->pestanaDelExamen(''));
    }

    public function test_las_solapas_del_examen_en_ginecologia_siguen_completas(): void
    {
        $gineco = AlcanceDeLaEspecialidad::de(self::GINECO);
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $gineco->pestanasDelExamenOcultas());

        $this->assertSame(PestanasDelExamen::claves(), array_column($pestanas->opciones(PestanasDelExamen::FISICO), 'clave'));
        $this->assertSame(PestanasDelExamen::MAMAS, $gineco->pestanaDelExamen(PestanasDelExamen::MAMAS));
    }

    public function test_la_pareja_y_el_embarazo_solo_se_ocultan_en_pediatria(): void
    {
        $this->assertFalse(AlcanceDeLaEspecialidad::de('pediatria')->muestraLaPareja());
        $this->assertFalse(AlcanceDeLaEspecialidad::de('pediatria')->muestraElEmbarazo());
        $this->assertTrue(AlcanceDeLaEspecialidad::de(self::GINECO)->muestraLaPareja());
        $this->assertTrue(AlcanceDeLaEspecialidad::de(null)->muestraElEmbarazo());
    }
}
