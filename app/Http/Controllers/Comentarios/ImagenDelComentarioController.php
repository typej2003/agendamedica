<?php

namespace App\Http\Controllers\Comentarios;

use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Http\Controllers\Controller;
use App\Models\Comentario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **Servir una imagen adjunta a un comentario** de "Ayúdanos a mejorar" (O-25).
 *
 * Es el **único camino** al archivo: vive en un disco privado (`storage/app/comentarios`, fuera de `public/` y
 * sin enlace simbólico), así que ninguna URL del servidor web lo alcanza. Hay dos puertas, y cada una
 * comprueba quién pide:
 *
 *  - **El autor**, desde «Mis comentarios» (`/clinica/ayuda`): sólo ve las capturas de **sus** comentarios. Un
 *    comentario ajeno da **404** —no 403—, la misma vara que el resto de la web clínica: no se confirma que
 *    exista.
 *  - **Quien atiende la bandeja** (`/admin/comentarios`, Root o Administrador): las ve todas, porque su trabajo
 *    es leer el reporte.
 *
 * La ruta del sistema **nunca** sale en la respuesta: se sirve con `Storage::response()`, que pone el
 * `Content-Type` real y un `Content-Disposition` con el nombre limpio del archivo.
 */
class ImagenDelComentarioController extends Controller
{
    /** La ruta del **autor** del comentario (la de «Mis comentarios»). */
    public function delAutor(Request $request, int $comentario, int $imagen): StreamedResponse
    {
        $fila = Comentario::find($comentario);

        // Ni un comentario que no existe ni uno de otra cuenta: los dos son "no hay nada que servir".
        abort_if($fila === null || (int) $fila->user_id !== (int) $request->user()->getAuthIdentifier(), 404);

        return $this->servir($fila, $imagen);
    }

    /** La ruta de la **bandeja** (`/admin`): la ve quien atiende los comentarios. */
    public function deLaBandeja(Request $request, int $comentario, int $imagen): StreamedResponse
    {
        $usuario = $request->user();

        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);

        $fila = Comentario::find($comentario);

        abort_if($fila === null, 404);

        return $this->servir($fila, $imagen);
    }

    /**
     * Abre el archivo de una imagen **de ese comentario**. La fila se resuelve por el comentario —no por el id
     * suelto—, así que una imagen de otro comentario tampoco se sirve aunque se adivine el número.
     */
    private function servir(Comentario $comentario, int $imagen): StreamedResponse
    {
        $fila = $comentario->imagenes()->whereKey($imagen)->first();

        abort_if($fila === null, 404);

        $disco = Storage::disk($fila->disco ?: ArchivosDeLosComentarios::DISCO);

        abort_unless($disco->exists($fila->ruta), 404);

        return $disco->response($fila->ruta, $fila->nombreVisible(), [
            // El tipo real con el que se guardó: una captura se muestra en la pestaña.
            'Content-Type' => $fila->tipo ?: 'application/octet-stream',
            // Los datos de un paciente no se cachean en un proxy compartido ni se indexan.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
