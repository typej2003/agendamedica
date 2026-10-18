<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\ImagenDeColposcopia;
use App\Clinica\Consulta\Vistas\DibujosDeColposcopiaVista;
use App\Models\DibujoDeColposcopia;
use Tests\TestCase;

/**
 * **La tarjeta del dibujo del cuello uterino** (A-13), sin base de datos: los dos lienzos, la base de cada uno, sus
 * imágenes y las URLs que arma. Todo llega por el constructor, así que el caso no toca la base (sólo necesita la app
 * para `asset()`, como el resto de los ViewModels que devuelven URLs).
 */
class DibujosDeColposcopiaVistaTest extends TestCase
{
    private function vista(array $guardados = []): DibujosDeColposcopiaVista
    {
        return new DibujosDeColposcopiaVista(
            fn (string $variante) => '/clinica/consulta/42/colposcopia/' . $variante,
            fn (string $variante) => '/clinica/consulta/42/colposcopia/' . $variante,
            $guardados,
        );
    }

    private function porVariante(array $lienzos): array
    {
        $porVariante = [];
        foreach ($lienzos as $lienzo) {
            $porVariante[$lienzo['variante']] = $lienzo;
        }

        return $porVariante;
    }

    public function test_sin_nada_guardado_estan_las_dos_variantes_con_la_vectorial_por_defecto(): void
    {
        $lienzos = $this->vista()->lienzos();

        $this->assertSame(['nulipara', 'multipara'], array_column($lienzos, 'variante'));
        $this->assertSame('Cuello nulípara', $lienzos[0]['titulo']);
        $this->assertSame('Orificio transverso', $lienzos[0]['descripcion']);
        $this->assertSame('Cuello multípara', $lienzos[1]['titulo']);
        $this->assertSame('Orificio redondo', $lienzos[1]['descripcion']);

        foreach ($lienzos as $lienzo) {
            $this->assertSame(DibujoDeColposcopia::VECTOR, $lienzo['base']);
            $this->assertNull($lienzo['dibujo'], 'Sin trazo no se ofrece la imagen guardada.');
            $this->assertSame('/clinica/consulta/42/colposcopia/' . $lienzo['variante'], $lienzo['guardar']);
        }
    }

    public function test_cada_lienzo_lleva_sus_dos_bases_con_la_elegida_marcada(): void
    {
        $nulipara = $this->porVariante($this->vista()->lienzos())['nulipara'];

        $this->assertSame(['vector', 'escritorio'], array_column($nulipara['bases'], 'valor'));
        $this->assertSame(['Vectorial', 'Del escritorio'], array_column($nulipara['bases'], 'texto'));
        $this->assertSame([true, false], array_column($nulipara['bases'], 'elegida'));

        // Las imágenes: la vectorial es el SVG y la del escritorio, el PNG escalado, con el marco de las mamas.
        $this->assertStringEndsWith('img/clinica/colposcopia-nulipara.svg', $nulipara['bases'][0]['imagen']);
        $this->assertStringEndsWith('img/clinica/colposcopia-nulipara.png', $nulipara['bases'][1]['imagen']);
        $this->assertStringEndsWith('img/clinica/colposcopia-nulipara.svg', $nulipara['imagen']);
    }

    public function test_el_estado_guardado_dice_la_base_y_si_hay_trazo(): void
    {
        $lienzos = $this->porVariante($this->vista([
            DibujoDeColposcopia::MULTIPARA => ['base' => DibujoDeColposcopia::ESCRITORIO, 'dibujo' => true],
            DibujoDeColposcopia::NULIPARA => ['base' => DibujoDeColposcopia::VECTOR, 'dibujo' => false],
        ])->lienzos());

        $this->assertSame(DibujoDeColposcopia::ESCRITORIO, $lienzos['multipara']['base']);
        $this->assertSame('/clinica/consulta/42/colposcopia/multipara', $lienzos['multipara']['dibujo']);
        $this->assertStringEndsWith('img/clinica/colposcopia-multipara.png', $lienzos['multipara']['imagen']);

        $this->assertSame(DibujoDeColposcopia::VECTOR, $lienzos['nulipara']['base']);
        $this->assertNull($lienzos['nulipara']['dibujo']);
    }

    /** Una base que no conocemos (una fila vieja o un valor raro) no rompe la pantalla: cae en la vectorial. */
    public function test_una_base_desconocida_cae_en_la_vectorial(): void
    {
        $lienzo = $this->porVariante($this->vista([
            DibujoDeColposcopia::NULIPARA => ['base' => 'otra', 'dibujo' => true],
        ])->lienzos())['nulipara'];

        $this->assertSame(DibujoDeColposcopia::VECTOR, $lienzo['base']);
        $this->assertSame(DibujoDeColposcopia::VECTOR, ImagenDeColposcopia::base('otra'));
        $this->assertSame('img/clinica/colposcopia-nulipara.svg', ImagenDeColposcopia::ruta('nulipara', 'otra'));
    }

    public function test_las_herramientas_son_las_mismas_de_las_mamas_y_la_ayuda_lo_explica(): void
    {
        $vista = $this->vista();

        $this->assertCount(5, $vista->colores());
        $this->assertArrayHasKey('#d62828', $vista->colores());
        $this->assertStringContainsString('Incluir imágenes', $vista->ayuda());
        $this->assertStringContainsString('imagen base', $vista->ayuda());
    }
}
