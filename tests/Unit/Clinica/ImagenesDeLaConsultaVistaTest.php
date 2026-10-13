<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **subida de imágenes de una consulta** (WEB-4.7, ítem 7 del panel) se prueba sin base
 * de datos ni HTTP: las filas ya leídas por `ImagenesDeLaConsulta` → los textos, las banderas y las URLs
 * (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá:
 *
 *  - **La bandera que decide si hay enlace**: una imagen que subió la web (`enElServidor`) se abre por su
 *    ruta autorizada; una del escritorio —que guardó la ruta del archivo de la PC del consultorio— **no**
 *    ofrece un enlace que daría 404, dice dónde quedó el archivo.
 *  - Las **URLs por fila**: la descarga y el borrado llevan el id de la imagen (una función, no una
 *    concatenación).
 *  - Los **estados vacíos** y el aviso de las filas del escritorio, que aparece sólo si hay alguna.
 *  - Los textos de ayuda que salen de la política compartida (`ArchivosClinicos`): tipos y tope.
 */
class ImagenesDeLaConsultaVistaTest extends TestCase
{
    /** @param array<int,array{0:int,1:?string,2:bool,3:?string,4:?string}> $filas id, descripción, enElServidor, nombre, fecha */
    private function imagenes(array $filas): Collection
    {
        return collect($filas)->map(fn (array $fila) => (object) [
            'id' => $fila[0],
            'descripcion' => $fila[1] ?? null,
            'fecha' => $fila[4] ?? '2026-10-11 09:30:00',
            'orden' => 0,
            'enElServidor' => $fila[2],
            'disco' => $fila[2] ? ArchivosClinicos::DISCO : null,
            'archivo' => $fila[2] ? 'examenes/med/2026-10/abc.jpg' : null,
            'nombre' => $fila[3] ?? null,
        ]);
    }

    private function vista(array $filas = []): ImagenesDeLaConsultaVista
    {
        return new ImagenesDeLaConsultaVista(
            $this->imagenes($filas),
            '/clinica/consulta/42/examenes',
            fn (int $id) => "/clinica/consulta/42/examenes/{$id}",
            fn (int $id) => "/clinica/consulta/42/examenes/{$id}",
        );
    }

    public function test_una_consulta_sin_imagenes_lo_dice(): void
    {
        $vm = $this->vista();

        $this->assertFalse($vm->hay());
        $this->assertSame(0, $vm->total());
        $this->assertSame('Sin imágenes', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
        $this->assertNull($vm->avisoDelEscritorio());

        $vacio = $vm->sinImagenes();
        $this->assertSame('Todavía no hay imágenes en esta consulta', $vacio['titulo']);
        $this->assertStringContainsString('descripción', $vacio['texto']);
    }

    public function test_una_imagen_de_la_web_ofrece_su_enlace_autorizado(): void
    {
        $vm = $this->vista([[12, 'Colposcopia: ectopia leve', true, '20211025_121548.jpg']]);

        $this->assertTrue($vm->hay());
        $this->assertSame(1, $vm->total());
        $this->assertSame('1 imagen', $vm->estado()['texto']);
        $this->assertNull($vm->avisoDelEscritorio());

        $imagen = $vm->imagenes()[0];
        $this->assertTrue($imagen['puedeVerse']);
        $this->assertSame('/clinica/consulta/42/examenes/12', $imagen['url']);
        $this->assertSame('/clinica/consulta/42/examenes/12', $imagen['urlQuitar']);
        $this->assertSame('Colposcopia: ectopia leve', $imagen['descripcion']);
        $this->assertSame('20211025_121548.jpg', $imagen['nombre']);
        // La fecha es la de la subida (`created_at`), ya formateada como el resto de la web.
        $this->assertSame('11/10/2026', $imagen['fecha']);
        $this->assertSame('En el servidor', $imagen['origen']);
    }

    public function test_una_imagen_del_escritorio_se_lista_pero_no_ofrece_enlace(): void
    {
        // El escritorio guarda la ruta del archivo en el disco de la PC (`imagen_consulta.imagen`, medido:
        // `C:\Users\IBYTE\Downloads\…`): el archivo no está en el servidor y no hay nada que servir. La
        // fila se lista —el médico tiene que saber que existe— y se dice dónde está.
        $vm = $this->vista([[7, null, false, '20211025_121548.jpg']]);

        $imagen = $vm->imagenes()[0];
        $this->assertFalse($imagen['puedeVerse']);
        $this->assertNull($imagen['url']);
        $this->assertSame('En el disco del consultorio', $imagen['origen']);
        $this->assertStringContainsString('PC donde se cargó', $imagen['detalle']);

        // La descripción vacía del legado se lee como "sin descripción": la pantalla lo dice con palabras.
        $this->assertNull($imagen['descripcion']);

        $this->assertNotNull($vm->avisoDelEscritorio());
        $this->assertStringContainsString('la ruta del archivo', $vm->avisoDelEscritorio());
    }

    public function test_el_conteo_plural_y_el_aviso_del_escritorio_conviven(): void
    {
        // Una de la web y una del escritorio: la lista es mixta y el aviso aparece igual, porque hay al
        // menos una que no se puede abrir.
        $vm = $this->vista([
            [1, 'Eco pélvico', true, 'a.jpg'],
            [2, 'Colposcopia vieja', false, 'b.jpg'],
        ]);

        $this->assertSame('2 imágenes', $vm->estado()['texto']);
        $this->assertTrue($vm->imagenes()[0]['puedeVerse']);
        $this->assertFalse($vm->imagenes()[1]['puedeVerse']);
        $this->assertNotNull($vm->avisoDelEscritorio());
    }

    public function test_la_ayuda_sale_de_la_politica_de_archivos(): void
    {
        $vm = $this->vista();

        // Los tipos y el tope no se repiten en la vista: salen de `ArchivosClinicos`, que es la única
        // fuente de la política de la subida.
        $this->assertStringContainsString(ArchivosClinicos::textosAceptados(), $vm->ayudaArchivo());
        $this->assertStringContainsString(ArchivosClinicos::textoMaximo(), $vm->ayudaArchivo());
        $this->assertStringContainsString('disco privado', $vm->ayudaArchivo());

        $this->assertSame('/clinica/consulta/42/examenes', $vm->urlSubir());
        $this->assertStringContainsString('imagen', $vm->avisoSinGuardar());
        $this->assertStringContainsString('Qué es la imagen', $vm->ayudaDescripcion());
    }

    public function test_la_descripcion_escrita_sobrevive_al_error_de_validacion(): void
    {
        // El archivo no se puede repoblar (un `<input type="file">` no acepta un valor), pero la
        // descripción sí: la vista la muestra con el `old()` que le pasa el controlador.
        $vm = $this->vista();

        $this->assertSame('Colposcopia', $vm->descripcionElegida('Colposcopia'));
        $this->assertSame('', $vm->descripcionElegida());
        // `old()` devuelve `null` cuando el campo no vino en el POST: no puede reventar la pantalla (pasó
        // en Chrome, con un 500, antes de que el parámetro fuera nullable).
        $this->assertSame('', $vm->descripcionElegida(null));
    }
}
