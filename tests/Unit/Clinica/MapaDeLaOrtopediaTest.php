<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrtopedia;
use PHPUnit\Framework\TestCase;

/** **El mapa de la plantilla ortopédica** (Traumatología, 2026-11-07): columnas, rótulos y el `1`/`0` de las casillas. */
class MapaDeLaOrtopediaTest extends TestCase
{
    public function test_las_columnas_son_las_de_la_tabla_del_legado(): void
    {
        $this->assertSame([
            'botas', 'zapatos', 'normal', 'soporte', 'thomas', 'plantilla',
            'tacon_int', 'tacon_ext', 'suela_int', 'suela_ext',
            'borde_int_rect', 'borde_int_abdu', 'suela_normal', 'suela_antirres',
            'aparatos', 'observacion',
        ], MapaDeLaOrtopedia::columnas());
    }

    public function test_las_casillas_son_marcas_y_los_textos_respetan_el_ancho(): void
    {
        $campos = collect(MapaDeLaOrtopedia::campos())->keyBy('clave');

        $this->assertSame('marca', $campos['botas']['tipo']);
        $this->assertSame('marca', $campos['suela_antirres']['tipo']);
        $this->assertSame('parrafo', $campos['observacion']['tipo']);
        $this->assertSame(6, $campos['soporte']['max']);
        $this->assertSame(8, $campos['thomas']['max']);
        $this->assertSame(20, $campos['aparatos']['max']);
        $this->assertSame(8, $campos->filter(fn (array $c) => $c['tipo'] === 'marca')->count());
    }
}
