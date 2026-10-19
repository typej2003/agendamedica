<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EndoscopiaDigestiva;
use PHPUnit\Framework\TestCase;

/**
 * **Endoscopías digestivas** (gastroenterología pediátrica, 2026-10-19): el mapa del dato.
 *
 * Lo que se fija, contra el esquema del legado:
 *  - cada tipo tiene **su** tabla (`endosc_diges_super` / `endosc_diges_infer`);
 *  - las columnas son las del DataWindow, sin las de las imágenes (que guardan rutas de la PC del consultorio);
 *  - el inferior guarda esófago, estómago y duodeno con su estado de **un carácter** (`char(1)`).
 */
class EndoscopiaDigestivaTest extends TestCase
{
    public function test_los_dos_tipos_y_sus_tablas(): void
    {
        $this->assertSame(['superior', 'inferior'], EndoscopiaDigestiva::tipos());
        $this->assertSame('endosc_diges_super', EndoscopiaDigestiva::tabla('superior'));
        $this->assertSame('endosc_diges_infer', EndoscopiaDigestiva::tabla('inferior'));
        $this->assertTrue(EndoscopiaDigestiva::existe('superior'));
        $this->assertFalse(EndoscopiaDigestiva::existe('renal'));
    }

    public function test_la_superior_tiene_los_campos_del_escritorio(): void
    {
        $campos = EndoscopiaDigestiva::campos('superior');

        $this->assertSame(
            ['fecha', 'equipo', 'indicacion', 'sedacion', 'descripcion', 'conclusiones', 'full_texto'],
            array_keys($campos),
        );
        $this->assertSame('descripcion', $campos['descripcion']['columna']);
        // `full_texto` es lo que imprime el escritorio.
        $this->assertSame('full_texto', $campos['full_texto']['columna']);
    }

    public function test_la_inferior_guarda_los_tres_organos_con_su_estado(): void
    {
        $campos = EndoscopiaDigestiva::campos('inferior');

        foreach (['esofago', 'estomago', 'duodeno'] as $organo) {
            $this->assertSame($organo . '_estado', $campos[$organo . '_estado']['columna']);
            $this->assertSame('opcion', $campos[$organo . '_estado']['clase']);
            $this->assertSame($organo . '_descripcion', $campos[$organo . '_descripcion']['columna']);
        }

        $this->assertContains('conclusiones', array_keys($campos));
        $this->assertContains('full_texto', array_keys($campos));
    }

    public function test_no_se_tocan_las_columnas_de_las_imagenes(): void
    {
        $columnas = EndoscopiaDigestiva::columnas('superior');

        // Las imágenes del escritorio guardan rutas de la PC del consultorio: la web no las reescribe.
        $this->assertNotContains('ima_primera', $columnas);
        $this->assertNotContains('obs_ima_primera', $columnas);
    }
}
