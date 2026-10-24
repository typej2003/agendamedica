<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\SeccionesDeLaConsulta;
use App\Clinica\Consulta\TextosDelExamen;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use PHPUnit\Framework\TestCase;

/**
 * WEB-12.1 y el modo medicina general (2026-10-22): qué partes de «Atender» aplican a cada especialidad. Lo que se fija es que
 * **ginecología no cambia**, que la pediatría pierde sólo lo ginecológico y que **cualquier otra especialidad** atiende con el
 * núcleo de medicina general.
 */
class AlcanceDeLaEspecialidadTest extends TestCase
{
    private const GINECO = 'ginecologia-y-obstetricia';

    /** Una especialidad sin módulos propios: es el modo medicina general. */
    private const GENERAL = 'cardiologia';

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
        foreach ([self::GINECO, null] as $slug) {
            $vm = $this->shell($slug);
            // O-22 retiró *Exámenes* (el ítem 7): la numeración del legado no se renumera.
            $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 13, 14], $this->numeros($vm), (string) $slug);
            $this->assertSame(13, $vm->totalItems());
        }
    }

    /**
     * **El modo medicina general** (Alexander, 2026-10-22): una especialidad que no es ginecología ni pediatría atiende con el
     * núcleo —sin el estudio ecográfico ni el control de embarazo—, que es lo que permite habilitar las nueve especialidades
     * mientras sus módulos propios llegan uno por uno.
     */
    public function test_una_especialidad_sin_modulos_propios_usa_el_nucleo_de_medicina_general(): void
    {
        $vm = $this->shell(self::GENERAL);

        // 11 de 11: los trece del panel menos el 5 (estudio ecográfico) y el 6 (control de embarazo). La numeración no se renumera.
        $this->assertSame([1, 2, 3, 4, 8, 9, 10, 11, 12, 13, 14], $this->numeros($vm));
        $this->assertSame(11, $vm->totalItems());
        $this->assertSame(11, $vm->totalListos());
    }

    /** En el modo general las dos secciones ginecológicas no existen en el catálogo: su URL directa cae en el estado vacío. */
    public function test_el_modo_general_no_abre_el_estudio_ni_el_embarazo(): void
    {
        foreach ([ConsultaShellVista::SECCION_EMBARAZO, ConsultaShellVista::SECCION_ESTUDIO] as $oculta) {
            $vm = new ConsultaShellVista(
                (object) ['id' => 1, 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'numhistoria' => '1001'],
                1, '2026-10-05', '/p', '/h', '/v', '/clinica/consulta/42', $oculta, null, false, [], null, [], false, self::GENERAL,
            );

            $this->assertNull($vm->seccion(), $oculta);
            $this->assertNull($vm->parcial(), $oculta);
        }
    }

    public function test_pediatria_pierde_el_estudio_ecografico_y_el_control_de_embarazo_y_conserva_la_numeracion(): void
    {
        $vm = $this->shell('pediatria');

        // Pediatría tiene **su** catálogo (2026-10-19): sin el ítem 5 del estudio —que en su árbol es el de
        // nefrología, y sólo aparece con ese postgrado— ni el control de embarazo. Numeración del legado: 1-4 y 6-15.
        $this->assertSame([1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], $this->numeros($vm));
        $this->assertSame(14, $vm->totalItems());
        // Los catorce tienen pantalla desde F9 (la pre-factura).
        $this->assertSame(14, $vm->totalListos());
    }

    public function test_una_seccion_oculta_no_se_abre_en_pediatria(): void
    {
        foreach ([ConsultaShellVista::SECCION_EMBARAZO, ConsultaShellVista::SECCION_ESTUDIO] as $oculta) {
            $vm = new ConsultaShellVista(
                (object) ['id' => 1, 'nombres' => 'Ana', 'apellidos' => 'Pérez', 'numhistoria' => '1001'],
                1, '2026-10-05', '/p', '/h', '/v', '/clinica/consulta/42', $oculta, null, false, [], null, [], false, 'pediatria',
            );

            $this->assertNull($vm->seccion(), $oculta);
            $this->assertNull($vm->parcial(), $oculta);
        }
    }

    /**
     * **El estudio ecográfico no va en pediatría** (Alexander, 2026-10-19): la sección no se ofrece y no queda ningún tipo de
     * chip. Antes quedaba la eco abdominal, que era la respuesta por defecto de la pregunta 11 de `preguntas-para-alexander.md`.
     */
    public function test_el_estudio_ecografico_no_se_ofrece_en_pediatria(): void
    {
        $alcance = AlcanceDeLaEspecialidad::de('pediatria');

        $this->assertFalse($alcance->muestraElEstudio());
        $this->assertEqualsCanonicalizing(TiposDeEstudio::claves(), $alcance->tiposDeEstudioOcultos());
        // No hay ítem de estudio en el catálogo pediátrico (el 5 de pediatría es «Indicadores incidentes», que agrega
        // el postgrado de nefrología).
        $this->assertSame([], array_values(array_filter(
            SeccionesDeLaConsulta::todas('pediatria'),
            fn (array $item) => $item['seccion'] === ConsultaShellVista::SECCION_ESTUDIO,
        )));
    }

    public function test_el_estudio_en_ginecologia_sigue_como_antes(): void
    {
        $alcance = AlcanceDeLaEspecialidad::de(self::GINECO);

        $this->assertSame([TiposDeEstudio::ABDOMINAL], $alcance->tiposDeEstudioOcultos());
        $this->assertSame(TiposDeEstudio::GINECOLOGICO, $alcance->tipoDeEstudio(''));
        $this->assertSame(TiposDeEstudio::OBSTETRICO, $alcance->tipoDeEstudio(TiposDeEstudio::OBSTETRICO));
        // El modo medicina general no ofrece ningún tipo: no tiene la sección (2026-10-22).
        $this->assertEqualsCanonicalizing(
            TiposDeEstudio::claves(),
            AlcanceDeLaEspecialidad::de(self::GENERAL)->tiposDeEstudioOcultos(),
        );
    }

    public function test_las_solapas_del_examen_en_pediatria_son_las_propias(): void
    {
        $pediatria = AlcanceDeLaEspecialidad::de('pediatria');
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $pediatria->pestanasDelExamenOcultas());

        $this->assertSame(
            [PestanasDelExamen::MEDICIONES, PestanasDelExamen::EXAMEN, PestanasDelExamen::CUESTIONARIO, PestanasDelExamen::NEUMOLOGIA],
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

    /**
     * **El modo medicina general** (2026-10-22): quedan el examen físico, el resultado de exámenes y la galería de imágenes. No
     * se ofrecen las de pediatría ni las cinco que son sólo de ginecología.
     */
    public function test_las_solapas_del_examen_en_el_modo_general_son_las_del_nucleo(): void
    {
        $general = AlcanceDeLaEspecialidad::de(self::GENERAL);
        $pestanas = new PestanasDelExamen(fn (string $c) => "/x/$c", $general->pestanasDelExamenOcultas());

        $this->assertSame(
            [PestanasDelExamen::FISICO, TextosDelExamen::RESULTADOS],
            array_column($pestanas->opciones(PestanasDelExamen::FISICO), 'clave'),
        );
        $this->assertSame(PestanasDelExamen::FISICO, $general->pestanaDelExamen(''));
    }

    public function test_la_pareja_el_embarazo_y_el_estudio_solo_se_ofrecen_en_ginecologia(): void
    {
        foreach (['pediatria', self::GENERAL, 'traumatologia-y-ortopedia'] as $slug) {
            $alcance = AlcanceDeLaEspecialidad::de($slug);

            $this->assertFalse($alcance->muestraLaPareja(), $slug);
            $this->assertFalse($alcance->muestraElEmbarazo(), $slug);
            $this->assertFalse($alcance->muestraElEstudio(), $slug);
        }

        $this->assertTrue(AlcanceDeLaEspecialidad::de(self::GINECO)->muestraLaPareja());
        $this->assertTrue(AlcanceDeLaEspecialidad::de(null)->muestraElEmbarazo());
        $this->assertTrue(AlcanceDeLaEspecialidad::de('ginecologia')->muestraElEstudio());
    }

    /**
     * **Las plantillas de lo que la especialidad no tiene** (2026-10-18): en pediatría no se ofrecen las de colposcopía, citología,
     * histeroscopía, resultado de exámenes, examen ginecológico ni los ecos, porque no tiene esas solapas ni esos estudios.
     */
    public function test_pediatria_no_ofrece_las_plantillas_ginecologicas(): void
    {
        $ocultos = AlcanceDeLaEspecialidad::de('pediatria')->tiposDePlantillaOcultos();

        // Las de las cuatro solapas de texto del examen, el resumen ginecológico y **los siete ecos** (en pediatría
        // no hay sección de estudio desde el 2026-10-19: Alexander confirmó que el ítem no va).
        foreach (['CO', 'CI', 'HI', 'RE', 'EG', 'UG', 'O1', 'O2', 'O3', 'ED', 'UM', 'UA'] as $tipo) {
            $this->assertContains($tipo, $ocultos, 'Pediatría no ofrece las plantillas de ' . $tipo . '.');
        }

        // Los dos ecos que la web no ofrece en ninguna especialidad tampoco.
        $this->assertContains('EM', $ocultos);
        $this->assertContains('ER', $ocultos);

        // Y se quedan las de las secciones que sí tiene, incluido el resumen físico de su solapa «Examen físico».
        foreach (['EV', 'IM', 'DI', 'EA', 'TR', 'RF'] as $tipo) {
            $this->assertNotContains($tipo, $ocultos, 'Pediatría conserva las plantillas de ' . $tipo . '.');
        }
    }

    /**
     * Fuera de pediatría no cambia nada de lo que había… salvo las **tres plantillas de *Exámenes/Estudios*** (`EX`, `IG`, `EE`), que
     * son de la consulta pediátrica y no tienen pantalla en el resto (2026-10-19).
     */
    public function test_ginecologia_solo_oculta_las_plantillas_de_examenes_estudios(): void
    {
        foreach ([self::GINECO, null] as $slug) {
            $this->assertEqualsCanonicalizing(
                ['EX', 'IG', 'EE'],
                AlcanceDeLaEspecialidad::de($slug)->tiposDePlantillaOcultos(),
                (string) $slug,
            );
        }
    }

    /**
     * **El modo medicina general** (2026-10-22) no ofrece las plantillas de lo que no tiene: las cinco ginecológicas, los siete ecos
     * —la sección no está— y las tres de pediatría. Las que cualquier especialidad usa se quedan.
     */
    public function test_el_modo_general_solo_conserva_las_plantillas_del_nucleo(): void
    {
        $ocultos = AlcanceDeLaEspecialidad::de(self::GENERAL)->tiposDePlantillaOcultos();

        foreach (['CO', 'CI', 'HI', 'EG', 'EM', 'ER', 'EX', 'IG', 'EE'] as $tipo) {
            $this->assertContains($tipo, $ocultos, 'El modo general no ofrece las plantillas de ' . $tipo . '.');
        }

        // Las de los siete ecos tampoco: no hay estudio en esta especialidad.
        foreach (['UG', 'O1', 'O2', 'O3', 'ED', 'UM', 'UA'] as $tipo) {
            $this->assertContains($tipo, $ocultos, 'El modo general no ofrece las plantillas de ' . $tipo . '.');
        }

        foreach (['EV', 'IM', 'DI', 'EA', 'TR', 'RF', 'RE'] as $tipo) {
            $this->assertNotContains($tipo, $ocultos, 'El modo general conserva las plantillas de ' . $tipo . '.');
        }
    }

    /**
     * Los **formatos en blanco** de los ecos que la especialidad no tiene se deducen de los estudios que no ofrece, no de una lista
     * de formatos paralela: en pediatría —y en el modo medicina general, que tampoco tiene la sección— son los cuatro del ítem 5.
     */
    public function test_pediatria_y_el_modo_general_no_ofrecen_los_formatos_en_blanco_de_los_ecos(): void
    {
        $deLosEcos = [
            DocumentoImpreso::ECO_OBSTETRICO,
            DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
            DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
            DocumentoImpreso::ECO_DOPPLER,
        ];

        foreach (['pediatria', self::GENERAL] as $slug) {
            $this->assertEqualsCanonicalizing($deLosEcos, AlcanceDeLaEspecialidad::de($slug)->formatosEnBlancoOcultos(), $slug);
        }

        $this->assertSame([], AlcanceDeLaEspecialidad::de(self::GINECO)->formatosEnBlancoOcultos());
        $this->assertSame([], AlcanceDeLaEspecialidad::de(null)->formatosEnBlancoOcultos());
    }
}
