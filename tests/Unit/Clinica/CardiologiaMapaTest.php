<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
use App\Clinica\Consulta\Vistas\CardiologoVista;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa del dato de los estudios cardiológicos** (CAR-2, 2026-11-04): que la sección tenga una pestaña, que sus campos
 * apunten a las columnas del esquema de CardioReport y que el ViewModel los pinte con su valor guardado.
 */
class CardiologiaMapaTest extends TestCase
{
    public function test_la_seccion_tiene_el_ecocardiograma_y_su_tabla_es_la_del_legado(): void
    {
        $this->assertSame(['eco' => 'Ecocardiograma'], MapaDelCardiologo::PESTANAS[MapaDelCardiologo::SECCION]);
        $this->assertTrue(MapaDelCardiologo::esSeccion('cardiologia'));
        $this->assertFalse(MapaDelCardiologo::esSeccion('estudio'));
        $this->assertSame('ecocardiografia', array_key_first(MapaDelCardiologo::TABLAS));
    }

    public function test_los_campos_del_eco_son_las_columnas_del_esquema(): void
    {
        $columnas = array_column(MapaDelCardiologo::deLaPestana(MapaDelCardiologo::PESTANA_ECO), 'columna');

        $this->assertSame([
            'ha_ai', 'ha_rao', 'ha_gpavd', 'ha_cavidad_vd', 'ha_septum_iv', 'ha_pared_post',
            'ha_ddvi', 'ha_dsvi', 'ha_ivsvi', 'ha_fa', 'ha_fe', 'conclusiones',
        ], $columnas);
    }

    public function test_la_pestana_pedida_se_normaliza_a_la_que_existe(): void
    {
        $this->assertSame('eco', MapaDelCardiologo::pestana(MapaDelCardiologo::SECCION, ''));
        $this->assertSame('eco', MapaDelCardiologo::pestana(MapaDelCardiologo::SECCION, 'inventada'));
    }

    public function test_el_viewmodel_pinta_los_valores_guardados_y_su_unidad(): void
    {
        $vista = new CardiologoVista(
            MapaDelCardiologo::SECCION,
            MapaDelCardiologo::PESTANA_ECO,
            [],
            ['ha_ai' => '3,4', 'ha_fe' => '60', 'conclusiones' => 'Normal.'],
            '/guardar',
            '/volver',
        );

        $campos = collect($vista->campos())->keyBy('clave');

        $this->assertSame('3,4', $campos['ha_ai']['valor']);
        $this->assertSame('cm', $campos['ha_ai']['unidad']);
        $this->assertSame('%', $campos['ha_fe']['unidad']);
        $this->assertSame('parrafo', $campos['conclusiones']['tipo']);
        $this->assertSame('col-12', $campos['conclusiones']['columna']);
        $this->assertSame('Estudios cardiológicos', $vista->titulo());
        $this->assertSame([], $vista->pestanas());
    }
}
