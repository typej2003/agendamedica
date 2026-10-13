<?php

namespace Tests\Unit;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Archivos\TipoRealDeImagen;
use PHPUnit\Framework\TestCase;

/**
 * **El tipo REAL de una imagen por su cabecera** (WEB-T.1): lo que decide, igual en la herramienta de la PC y en el
 * servidor, qué archivos de las PC de los consultorios se suben. No mira el nombre ni el MIME del cliente.
 */
class TipoRealDeImagenTest extends TestCase
{
    /** Una caja `ftyp` de un HEIC/HEIF: tamaño + 'ftyp' + marca principal + versión + marcas compatibles. */
    public static function iso(string $principal, array $compatibles): string
    {
        $marcas = implode('', $compatibles);

        return pack('N', 16 + strlen($marcas)) . 'ftyp' . $principal . pack('N', 0) . $marcas . str_repeat("\0", 32);
    }

    public function test_reconoce_los_formatos_que_se_aceptan(): void
    {
        $this->assertSame(['mime' => 'image/jpeg', 'extension' => 'jpg'], TipoRealDeImagen::desdeCabecera("\xFF\xD8\xFF\xE0" . str_repeat("\0", 20)));
        $this->assertSame(['mime' => 'image/png', 'extension' => 'png'], TipoRealDeImagen::desdeCabecera("\x89PNG\r\n\x1A\n" . str_repeat("\0", 20)));
        $this->assertSame(['mime' => 'image/gif', 'extension' => 'gif'], TipoRealDeImagen::desdeCabecera('GIF89a' . str_repeat("\0", 20)));
        $this->assertSame(['mime' => 'image/gif', 'extension' => 'gif'], TipoRealDeImagen::desdeCabecera('GIF87a' . str_repeat("\0", 20)));
        $this->assertSame(['mime' => 'image/webp', 'extension' => 'webp'], TipoRealDeImagen::desdeCabecera('RIFF' . pack('V', 100) . 'WEBPVP8 ' . str_repeat("\0", 20)));
    }

    public function test_reconoce_heic_y_heif_por_la_marca_principal(): void
    {
        $this->assertSame(['mime' => 'image/heic', 'extension' => 'heic'], TipoRealDeImagen::desdeCabecera(self::iso('heic', ['mif1', 'heic'])));
        $this->assertSame(['mime' => 'image/heic', 'extension' => 'heic'], TipoRealDeImagen::desdeCabecera(self::iso('heix', ['mif1'])));
        $this->assertSame(['mime' => 'image/heif', 'extension' => 'heif'], TipoRealDeImagen::desdeCabecera(self::iso('mif1', ['heic'])));
        $this->assertSame(['mime' => 'image/heif', 'extension' => 'heif'], TipoRealDeImagen::desdeCabecera(self::iso('heif', ['mif1'])));
    }

    public function test_avif_y_otros_contenedores_iso_no_se_aceptan(): void
    {
        $this->assertNull(TipoRealDeImagen::desdeCabecera(self::iso('avif', ['mif1', 'avif'])));
        // `mif1` como principal pero con `avif` entre las compatibles: también es AVIF.
        $this->assertNull(TipoRealDeImagen::desdeCabecera(self::iso('mif1', ['avif', 'miaf'])));
        // Un MP4 es el mismo contenedor y no es una imagen.
        $this->assertNull(TipoRealDeImagen::desdeCabecera(self::iso('isom', ['isom', 'mp42'])));
    }

    public function test_lo_que_no_es_una_imagen_se_rechaza_aunque_el_nombre_diga_otra_cosa(): void
    {
        $this->assertNull(TipoRealDeImagen::desdeCabecera("MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00"), 'un ejecutable');
        $this->assertNull(TipoRealDeImagen::desdeCabecera('%PDF-1.4 ' . str_repeat('x', 30)), 'un PDF');
        $this->assertNull(TipoRealDeImagen::desdeCabecera("<?php echo 'hola'; ?>" . str_repeat(' ', 20)), 'código');
        $this->assertNull(TipoRealDeImagen::desdeCabecera("<svg xmlns='http://www.w3.org/2000/svg'></svg>"), 'un SVG');
        $this->assertNull(TipoRealDeImagen::desdeCabecera('BM' . str_repeat("\0", 30)), 'un BMP (no se acepta)');
        $this->assertNull(TipoRealDeImagen::desdeCabecera('texto plano que dice ser foto.jpg'), 'texto');
        $this->assertNull(TipoRealDeImagen::desdeCabecera(''), 'vacío');
        $this->assertNull(TipoRealDeImagen::desdeCabecera("\xFF\xD8"), 'cabecera truncada');
    }

    public function test_desde_archivo_lee_solo_el_comienzo_y_un_archivo_inexistente_es_null(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'tri');
        file_put_contents($ruta, "\x89PNG\r\n\x1A\n" . str_repeat('x', 5000));
        $this->assertSame('image/png', TipoRealDeImagen::desdeArchivo($ruta)['mime']);
        unlink($ruta);

        $this->assertNull(TipoRealDeImagen::desdeArchivo($ruta));
    }

    public function test_el_nombre_de_descarga_sale_limpio_y_con_la_extension_del_contenido(): void
    {
        $this->assertSame('IMG_4021.HEIC', ArchivosClinicos::nombreDeDescarga('IMG_4021.HEIC', 'importadas/m/t/2026-10/abc.heic'));
        $this->assertSame('20211025_121548.jpg', ArchivosClinicos::nombreDeDescarga('20211025_121548.jpg', 'importadas/m/t/2026-10/abc.jpg'));
        $this->assertSame('foto.JPEG', ArchivosClinicos::nombreDeDescarga('foto.JPEG', 'importadas/m/t/2026-10/abc.jpg'));
        // El contenido es un JPG aunque en la PC se llamara `.dat`: se baja con la extensión que le corresponde.
        $this->assertSame('escaneo.dat.jpg', ArchivosClinicos::nombreDeDescarga('escaneo.dat', 'importadas/m/t/2026-10/abc.jpg'));
        // Sin comillas, barras ni saltos de línea (va dentro de un Content-Disposition).
        $this->assertSame('a_b_c.png', ArchivosClinicos::nombreDeDescarga("a\"b
c.png", 'importadas/m/t/2026-10/abc.png'));
        $this->assertSame('x_y.png', ArchivosClinicos::nombreDeDescarga('x\y.png', 'importadas/m/t/2026-10/abc.png'));
        $this->assertSame('abc.png', ArchivosClinicos::nombreDeDescarga('  .. ', 'importadas/m/t/2026-10/abc.png'));
        $this->assertLessThanOrEqual(124, strlen(ArchivosClinicos::nombreDeDescarga(str_repeat('n', 400) . '.png', 'importadas/m/t/2026-10/abc.png')));
    }

    public function test_la_politica_de_la_web_no_cambio_y_la_de_importacion_suma_solo_heic(): void
    {
        // El formulario de la web sigue con los mismos cuatro formatos.
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], ArchivosClinicos::EXTENSIONES);
        $this->assertSame(['image/jpeg', 'image/png', 'image/gif', 'image/webp'], ArchivosClinicos::mimes());

        // La importación agrega HEIC/HEIF y nada más.
        $this->assertSame(['heic', 'heif'], array_values(array_diff(ArchivosClinicos::EXTENSIONES_IMPORTADAS, ArchivosClinicos::EXTENSIONES)));
        $this->assertSame(['image/heic', 'image/heif'], array_values(array_diff(ArchivosClinicos::mimesImportados(), ArchivosClinicos::mimes())));
        $this->assertSame(10 * 1024 * 1024, ArchivosClinicos::maximoBytes());

        $this->assertSame('image/heic', ArchivosClinicos::tipoPorExtension('importadas/x/y/abc.heic'));
        $this->assertTrue(ArchivosClinicos::esDeDescarga('importadas/x/y/abc.HEIF'));
        $this->assertFalse(ArchivosClinicos::esDeDescarga('importadas/x/y/abc.jpg'));
        $this->assertNull(ArchivosClinicos::tipoPorExtension('importadas/x/y/abc.exe'));
    }
}
