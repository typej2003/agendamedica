<?php

namespace App\Clinica\Impresion;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Models\Consulta;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * **Las imágenes de un estudio, listas para su hoja aparte** (WEB-11.13): las que el médico adjuntó al eco (`imagen_consulta` con la etiqueta
 * de ese estudio), en el orden en que las subió.
 *
 * Se **incrustan** en la página (data URI, reducidas a lo que una hoja necesita) en vez de apuntar a la ruta autorizada de descarga: así la hoja
 * se imprime igual desde la consulta del médico que desde la cita de la secretaría —que no abre la consulta— y no hay una URL de un archivo
 * clínico dando vueltas. Lo que no se puede leer o decodificar (un HEIC, un archivo que ya no está) se omite: una imagen rota no sale como un
 * cuadro vacío.
 */
final class ImagenesParaElInforme
{
    /** El lado mayor, en píxeles: una hoja A4 a 150 dpi con dos columnas no necesita más. */
    private const LADO_MAXIMO = 1200;

    public function __construct(private ImagenesDeLaConsulta $imagenes)
    {
    }

    /**
     * @param  string  $clave  la clave del estudio en `TagDeImagen` (`eco-ginecologico`, `eco-segundo`…)
     * @return array<int,array{src:string, descripcion:?string}>
     */
    public function de(Consulta $consulta, string $clave): array
    {
        $filas = $this->imagenes->deUnExamen((string) $consulta->reg_medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, $clave);

        $salida = [];

        foreach ($filas as $fila) {
            if (! $fila->enElServidor || $fila->descarga) {
                continue;
            }

            $src = $this->incrustar((string) $fila->disco, (string) $fila->archivo);

            if ($src !== null) {
                $salida[] = ['src' => $src, 'descripcion' => $fila->descripcion];
            }
        }

        return $salida;
    }

    /**
     * Un PNG **con transparencia**, incrustado sin aplanarlo (data URI). Es lo que necesita el trazo del cuello uterino
     * (A-13): la reducción de `incrustar()` lo recodifica como JPEG sobre fondo blanco —bien para una foto, pero taparía
     * la imagen base sobre la que se compone el dibujo—.
     */
    public function dePngTransparente(string $ruta): ?string
    {
        try {
            $almacen = Storage::disk(ArchivosClinicos::DISCO);

            if (! $almacen->exists($ruta)) {
                return null;
            }

            return 'data:image/png;base64,' . base64_encode($almacen->get($ruta));
        } catch (Throwable $e) {
            return null;
        }
    }

    private function incrustar(string $disco, string $ruta): ?string
    {
        try {
            $almacen = Storage::disk($disco);

            if (! $almacen->exists($ruta)) {
                return null;
            }

            $bytes = $almacen->get($ruta);

            return $this->reducida($bytes) ?? ('data:' . (ArchivosClinicos::tipoPorExtension($ruta) ?: 'image/jpeg') . ';base64,' . base64_encode($bytes));
        } catch (Throwable $e) {
            return null;
        }
    }

    /** La imagen reducida a `LADO_MAXIMO` y recodificada como JPEG, o `null` si GD no puede (se usa la original). */
    private function reducida(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $origen = @imagecreatefromstring($bytes);

        if ($origen === false) {
            return null;
        }

        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $escala = min(1, self::LADO_MAXIMO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        // Fondo blanco: un PNG con transparencia no se imprime negro.
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        ob_start();
        imagejpeg($destino, null, 82);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
