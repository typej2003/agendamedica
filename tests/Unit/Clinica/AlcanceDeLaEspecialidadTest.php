<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
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

    public function test_ginecologia_y_sin_especialidad_conservan_el_panel_de_trece_items(): void
    {
        foreach ([self::GINECO, null, 'cirugia'] as $slug) {
            $vm = $this->shell($slug);
            // O-22 retiró *Exámenes* (el ítem 7): la numeración del legado no se renumera.
            $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13, 14], $this->numeros($vm), (string) $slug);
            $this->assertSame(13, $vm->totalItems());
        }
    }

    public function test_pediatria_pierde_el_control_de_embarazo_y_conserva_la_numeracion(): void
    {
        $vm = $this->shell('pediatria');

        $this->assertSame([1, 2, 3, 4, 5, 8, 9, 10, 11, 12, 13, 14], $this->numeros($vm));
        $this->assertSame(12, $vm->totalItems());
        // Doce disponibles menos el control de embarazo (que en pediatría no aplica) = once.
        $this->assertSame(11, $vm->totalListos());
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

    public function test_las_solapas_del_examen_en_pediatria_son_las_propias(): void
    {
        $pediatria = AlcanceDeLaEspecialidad::de('pediatria');
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $pediatria->pestanasDelExamenOcultas());

        $this->assertSame(
            [PestanasDelExamen::MEDICIONES, PestanasDelExamen::EXAMEN, PestanasDelExamen::CUESTIONARIO, PestanasDelExamen::IMAGENES],
            array_column($pestanas->opciones(PestanasDelExamen::FISICO), 'clave'),
        );
        // Arranca en las mediciones, como el escritorio; la URL directa sigue abriendo el examen de ginecología.
        $this->assertSame(PestanasDelExamen::MEDICIONES, $pediatria->pestanaDelExamen(''));
        $this->assertSame(PestanasDelExamen::FISICO, $pediatria->pestanaDelExamen(PestanasDelExamen::FISICO));
    }

    public function test_las_solapas_del_examen_en_ginecologia_siguen_completas(): void
    {
        $gineco = AlcanceDeLaEspecialidad::de(self::GINECO);
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $gineco->pestanasDelExamenOcultas());

        // Las mismas solapas de siempre: las tres propias de pediatría (mediciones, examen y cuestionario) no se ofrecen.
        $this->assertSame(
            array_values(array_diff(PestanasDelExamen::claves(), PestanasDelExamen::PEDIATRICAS)),
            array_column($pestanas->opciones(PestanasDelExamen::FISICO), 'clave'),
        );
        $this->assertSame(PestanasDelExamen::FISICO, $gineco->pestanaDelExamen(''));
        $this->assertSame(PestanasDelExamen::MAMAS, $gineco->pestanaDelExamen(PestanasDelExamen::MAMAS));
    }

    public function test_la_pareja_y_el_embarazo_solo_se_ocultan_en_pediatria(): void
    {
        $this->assertFalse(AlcanceDeLaEspecialidad::de('pediatria')->muestraLaPareja());
        $this->assertFalse(AlcanceDeLaEspecialidad::de('pediatria')->muestraElEmbarazo());
        $this->assertTrue(AlcanceDeLaEspecialidad::de(self::GINECO)->muestraLaPareja());
        $this->assertTrue(AlcanceDeLaEspecialidad::de(null)->muestraElEmbarazo());
    }

    /**
     * **Las plantillas de lo que la especialidad no tiene** (2026-10-18): en pediatría no se ofrecen las de colposcopía, citología,
     * histeroscopía, resultado de exámenes, examen ginecológico ni los ecos, porque no tiene esas solapas ni esos estudios.
     */
    public function test_pediatria_no_ofrece_las_plantillas_ginecologicas(): void
    {
        $ocultos = AlcanceDeLaEspecialidad::de('pediatria')->tiposDePlantillaOcultos();

        // Las de las cuatro solapas de texto del examen, el resumen ginecológico y los siete ecos menos el abdominal.
        foreach (['CO', 'CI', 'HI', 'RE', 'EG', 'UG', 'O1', 'O2', 'O3', 'ED', 'UM'] as $tipo) {
            $this->assertContains($tipo, $ocultos, 'Pediatría no ofrece las plantillas de ' . $tipo . '.');
        }

        // Los dos ecos que la web no ofrece en ninguna especialidad tampoco.
        $this->assertContains('EM', $ocultos);
        $this->assertContains('ER', $ocultos);

        // Y se quedan las de las secciones que sí tiene, incluido el resumen físico de su solapa «Examen físico» y el eco
        // abdominal, que es el único estudio de pediatría.
        foreach (['EV', 'IM', 'DI', 'EA', 'TR', 'RF', 'UA'] as $tipo) {
            $this->assertNotContains($tipo, $ocultos, 'Pediatría conserva las plantillas de ' . $tipo . '.');
        }
    }

    public function test_ginecologia_y_las_demas_conservan_todas_las_plantillas(): void
    {
        foreach ([self::GINECO, 'cirugia', null] as $slug) {
            $this->assertSame([], AlcanceDeLaEspecialidad::de($slug)->tiposDePlantillaOcultos(), (string) $slug);
        }
    }

    /**
     * Los **formatos en blanco** de los ecos que la especialidad no tiene se deducen de los estudios que no ofrece, no de una lista
     * de formatos paralela: en pediatría son los cuatro del ítem 5 (los dos obstétricos y el Doppler).
     */
    public function test_pediatria_no_ofrece_los_formatos_en_blanco_de_los_ecos(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                DocumentoImpreso::ECO_OBSTETRICO,
                DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
                DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
                DocumentoImpreso::ECO_DOPPLER,
            ],
            AlcanceDeLaEspecialidad::de('pediatria')->formatosEnBlancoOcultos(),
        );

        $this->assertSame([], AlcanceDeLaEspecialidad::de(self::GINECO)->formatosEnBlancoOcultos());
        $this->assertSame([], AlcanceDeLaEspecialidad::de(null)->formatosEnBlancoOcultos());
    }
}
