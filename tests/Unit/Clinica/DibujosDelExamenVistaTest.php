<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\ImagenDelExamenVista;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use Tests\TestCase;

/**
 * **La tarjeta de la imagen del examen** (O-09), sin base de datos: el radiobutón de los dos modos, los lienzos del
 * esquema con su base, los de las fotos con su tamaño y las URLs que arma. Todo llega por el constructor (el estado ya
 * resuelto por `ImagenDelExamenDeLaConsulta`), así que el caso no toca la base: sólo necesita la app para `asset()`,
 * como el resto de los ViewModels que devuelven URLs.
 */
class DibujosDelExamenVistaTest extends TestCase
{
    /** @param array<string,mixed> $extra lo que cambia del estado resuelto */
    private function vista(array $extra = []): ImagenDelExamenVista
    {
        $estado = (object) array_merge([
            'examen' => 'colposcopia',
            'modo' => 'esquema',
            'foto' => null,
            'fotos' => collect(),
            'esquema' => [
                'nulipara' => ['base' => 'vector', 'trazo' => null],
                'multipara' => ['base' => 'vector', 'trazo' => null],
            ],
            'trazos' => [],
            'medidas' => [],
        ], $extra);

        return new ImagenDelExamenVista(
            'colposcopia',
            $estado,
            fn (string $variante, string $verbo) => '/lienzo/' . $verbo . '/' . $variante,
            fn (int $foto, string $verbo) => '/lienzo/' . $verbo . '/foto-' . $foto,
            fn (int $foto) => '/imagen/' . $foto,
            '/elegir',
            new ImagenesDeLaConsultaVista(collect(), '/subir', fn (int $id) => '/ver/' . $id, fn (int $id) => '/quitar/' . $id, 'colposcopia'),
        );
    }

    /** Una foto del estudio como la devuelve `ImagenesDeLaConsulta::deUnExamen()`. */
    private function foto(int $id, bool $enElServidor = true, bool $descarga = false): object
    {
        return (object) [
            'id' => $id,
            'nombre' => 'foto-' . $id . '.png',
            'enElServidor' => $enElServidor,
            'descarga' => $descarga,
            'disco' => 'clinica',
            'archivo' => 'examenes/foto-' . $id . '.png',
        ];
    }

    public function test_sin_nada_cargado_esta_el_esquema_con_sus_dos_variantes_y_su_base_por_defecto(): void
    {
        $vista = $this->vista();
        $lienzos = $vista->esquema();

        $this->assertTrue($vista->esEsquema());
        $this->assertFalse($vista->esFoto());
        $this->assertSame(['nulipara', 'multipara'], array_column($lienzos, 'variante'));
        $this->assertSame('Cuello nulípara', $lienzos[0]['titulo']);
        $this->assertSame('Orificio transverso', $lienzos[0]['descripcion']);
        $this->assertSame('Cuello multípara', $lienzos[1]['titulo']);

        foreach ($lienzos as $lienzo) {
            $this->assertSame('vector', $lienzo['base']);
            $this->assertNull($lienzo['dibujo'], 'Sin trazo no se ofrece la imagen guardada.');
            $this->assertSame('/lienzo/guardar/' . $lienzo['variante'], $lienzo['guardar']);
            // El marco del esquema es el de A-13, el mismo del SVG.
            $this->assertSame(520, $lienzo['ancho']);
            $this->assertSame(400, $lienzo['alto']);
        }

        $this->assertSame('col-12 col-xl-6', $vista->columnas());
    }

    public function test_el_radiobuton_ofrece_los_dos_modos_con_el_elegido_marcado(): void
    {
        $this->assertSame(['esquema', 'foto'], array_column($this->vista()->modos(), 'valor'));
        $this->assertSame(['Imagen predeterminada', 'Videocolposcopio'], array_column($this->vista()->modos(), 'texto'));
        $this->assertSame([true, false], array_column($this->vista()->modos(), 'activa'));

        $enLaFoto = $this->vista(['modo' => 'foto']);
        $this->assertSame([false, true], array_column($enLaFoto->modos(), 'activa'));
        $this->assertTrue($enLaFoto->esFoto());
        $this->assertSame('/elegir', $enLaFoto->urlElegir());
    }

    public function test_cada_lienzo_del_esquema_lleva_sus_dos_bases_con_la_elegida_marcada(): void
    {
        $nulipara = $this->vista()->esquema()[0];

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
        $lienzos = $this->vista([
            'esquema' => [
                'nulipara' => ['base' => 'vector', 'trazo' => null],
                'multipara' => ['base' => 'escritorio', 'trazo' => 'dibujos/12.png'],
            ],
        ])->esquema();

        $this->assertSame('escritorio', $lienzos[1]['base']);
        $this->assertSame('/lienzo/ver/multipara', $lienzos[1]['dibujo']);
        $this->assertStringEndsWith('img/clinica/colposcopia-multipara.png', $lienzos[1]['imagen']);
        $this->assertNull($lienzos[0]['dibujo']);
    }

    /** Una base que no conocemos (una fila vieja o un valor raro) no rompe la pantalla: cae en la vectorial. */
    public function test_una_base_desconocida_cae_en_la_vectorial(): void
    {
        $lienzo = $this->vista([
            'esquema' => [
                'nulipara' => ['base' => 'otra', 'trazo' => 'dibujos/12.png'],
                'multipara' => ['base' => 'vector', 'trazo' => null],
            ],
        ])->esquema()[0];

        $this->assertSame('vector', $lienzo['base']);
        $this->assertStringEndsWith('img/clinica/colposcopia-nulipara.svg', $lienzo['imagen']);
    }

    public function test_la_foto_es_un_lienzo_aparte_con_su_tamano_y_su_trazo(): void
    {
        $vista = $this->vista([
            'modo' => 'foto',
            'foto' => $this->foto(7),
            'fotos' => collect([$this->foto(7)]),
            'trazos' => [7 => 'dibujos/foto-7.png'],
            'medidas' => [7 => [2000, 1000]],
        ]);

        $lienzos = $vista->fotos();
        $this->assertCount(1, $lienzos);
        $this->assertSame(7, $lienzos[0]['id']);
        $this->assertTrue($lienzos[0]['activa']);
        $this->assertSame('/imagen/7', $lienzos[0]['imagen'], 'La base del lienzo es la foto, no el trazo.');
        $this->assertSame('/lienzo/ver/foto-7', $lienzos[0]['dibujo']);
        $this->assertSame('/lienzo/guardar/foto-7', $lienzos[0]['guardar']);
        // La foto se dibuja en sus proporciones, achicada al tope del lienzo (2000×1000 → 1600×800).
        $this->assertSame(1600, $lienzos[0]['ancho']);
        $this->assertSame(800, $lienzos[0]['alto']);
        $this->assertSame(7, $vista->fotoElegida());
        // El texto de la baja de la foto avisa que las marcas se van con ella (lo arma la tarjeta, no el ViewModel);
        // el del lienzo dice que sólo se borra la marca.
        $this->assertStringContainsString('La foto queda', $vista->avisoBorrarFoto());
    }

    public function test_con_varias_fotos_hay_que_elegir_cual_se_marca(): void
    {
        $dos = $this->vista([
            'modo' => 'foto',
            'foto' => $this->foto(8),
            'fotos' => collect([$this->foto(8), $this->foto(9)]),
        ]);

        $this->assertTrue($dos->hayQueElegirLaFoto());
        $this->assertSame([true, false], array_column($dos->fotos(), 'activa'));

        $una = $this->vista(['modo' => 'foto', 'foto' => $this->foto(8), 'fotos' => collect([$this->foto(8)])]);
        $this->assertFalse($una->hayQueElegirLaFoto());
    }

    public function test_una_foto_que_el_navegador_no_muestra_no_tiene_lienzo(): void
    {
        $vista = $this->vista([
            'modo' => 'foto',
            'foto' => $this->foto(3, true, true),
            'fotos' => collect([$this->foto(3, true, true)]),
        ]);

        $this->assertSame([], $vista->fotos());
        $this->assertTrue($vista->hayFotos());
        $this->assertTrue($vista->hayFotosQueNoSeMuestran());
        $this->assertStringContainsString('no se puede mostrar', $vista->sinFotos()['titulo']);
    }

    public function test_sin_fotos_el_modo_de_la_foto_lo_dice(): void
    {
        $vista = $this->vista(['modo' => 'foto', 'foto' => null, 'fotos' => collect()]);

        $this->assertFalse($vista->hayFotos());
        $this->assertSame([], $vista->fotos());
        $this->assertStringContainsString('Todavía no hay foto', $vista->sinFotos()['titulo']);
        $this->assertSame('Sin foto', $vista->estado()['texto']);
    }

    public function test_la_pastilla_dice_que_imagen_es_y_si_esta_marcada(): void
    {
        $this->assertSame('Sin marcas', $this->vista()->estado()['texto']);

        $conTrazo = $this->vista([
            'esquema' => [
                'nulipara' => ['base' => 'vector', 'trazo' => 'dibujos/12.png'],
                'multipara' => ['base' => 'vector', 'trazo' => null],
            ],
        ]);
        $this->assertSame('Con marcas', $conTrazo->estado()['texto']);

        $foto = $this->vista(['modo' => 'foto', 'foto' => $this->foto(7), 'fotos' => collect([$this->foto(7)])]);
        $this->assertSame('Foto', $foto->estado()['texto']);
    }

    public function test_las_herramientas_son_las_mismas_de_las_mamas_y_la_ayuda_explica_los_dos_modos(): void
    {
        $vista = $this->vista();

        $this->assertCount(5, $vista->colores());
        $this->assertArrayHasKey('#d62828', $vista->colores());
        // Una línea de ayuda (guía de UI §10.1) que dice de qué se trata.
        $this->assertStringContainsString('esquema', $vista->ayuda());
        $this->assertStringContainsString('foto del estudio', $vista->ayuda());
        $this->assertStringContainsString('Imagen de la colposcopía', $vista->titulo());
    }
}
