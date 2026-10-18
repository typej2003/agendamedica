<?php

namespace App\Actions\Comentarios;

use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Models\Comentario;
use App\Models\ComentarioImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * **Guardar las imágenes adjuntas de un comentario** de "Ayúdanos a mejorar" (O-25).
 *
 * Es la mitad de escritura del ciclo de vida de un adjunto; la baja la hace `BorrarImagenesDelComentario`.
 *
 * ## Lo que no puede quedar
 *
 * Un adjunto es un archivo **y** una fila, y los dos tienen que existir o no existir juntos:
 *
 *  - Si el archivo no se pudo escribir (disco lleno, sin permisos), no se crea la fila y se avisa.
 *  - Si la fila no se pudo crear, el archivo que se acababa de escribir **se borra** antes de propagar: un
 *    archivo de un comentario no lo puede leer nadie —la descarga resuelve por id de fila— y quedaría ocupando
 *    lugar para siempre.
 *  - Si falla la segunda de tres imágenes, se borran las que ya se habían escrito en esta llamada: el
 *    comentario no se guarda a medias. Quien llama (`EnviarComentario`) envuelve todo en una transacción, así
 *    que las filas de las anteriores también se van.
 *
 * ## Dónde vive cada archivo
 *
 * En el disco privado `comentarios` (`storage/app/comentarios`, fuera de `public/`), bajo la carpeta de la
 * cuenta y el mes: lo decide `ArchivosDeLosComentarios`, que es también la política de lo que se acepta (5 MB,
 * PNG/JPG/WEBP, tipo **real**). Acá no se valida nada de eso: llega ya validado.
 */
final class GuardarImagenesDelComentario
{
    /**
     * @param  array<int,UploadedFile>  $archivos
     * @return array<int,ComentarioImagen>  las filas creadas, en el orden en que llegaron
     *
     * @throws RuntimeException  si un archivo no se pudo guardar en el disco
     */
    public function ejecutar(Comentario $comentario, array $archivos): array
    {
        $disco = ArchivosDeLosComentarios::DISCO;
        $guardadas = [];
        $escritas = [];

        try {
            foreach ($archivos as $archivo) {
                if (! $archivo instanceof UploadedFile) {
                    continue;
                }

                $cuenta = (int) $comentario->user_id;
                $ruta = ArchivosDeLosComentarios::ruta($cuenta, $archivo);

                if (! Storage::disk($disco)->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
                    throw new RuntimeException('No se pudo guardar la imagen en el disco de adjuntos.');
                }

                $escritas[] = $ruta;

                $guardadas[] = ComentarioImagen::create([
                    'comentario_id' => $comentario->id,
                    'disco' => $disco,
                    // La ruta relativa al disco, nunca la del sistema: es lo que la descarga resuelve con el id
                    // de la fila, y lo que permite mudar el disco sin tocar las filas.
                    'ruta' => $ruta,
                    // El tipo real con el que se guardó: se manda al servir el archivo (y evita que `finfo`
                    // dude, por ejemplo con un WEBP).
                    'tipo' => $archivo->getMimeType(),
                    'tamano' => (int) $archivo->getSize(),
                    'nombre_original' => $this->nombreOriginal($archivo),
                ]);
            }
        } catch (Throwable $e) {
            // Ni archivos huérfanos ni filas a medias: se deshace lo que hizo esta llamada y se propaga.
            foreach ($guardadas as $guardada) {
                $guardada->delete();
            }

            foreach (array_reverse($escritas) as $ruta) {
                Storage::disk($disco)->delete($ruta);
            }

            throw $e;
        }

        return $guardadas;
    }

    /**
     * El nombre que traía el archivo en la máquina del usuario, limpio: se muestra y se pone en la descarga, así
     * que no puede llevar barras, comillas ni caracteres de control.
     */
    private function nombreOriginal(UploadedFile $archivo): ?string
    {
        $nombre = basename(str_replace('\\', '/', (string) $archivo->getClientOriginalName()));
        $nombre = preg_replace('~[[:cntrl:]"\\\\/:*?<>|;]+~u', '_', trim($nombre)) ?? '';
        $nombre = mb_substr(trim($nombre, " ._"), 0, 255);

        return $nombre === '' ? null : $nombre;
    }
}
