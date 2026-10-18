<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Comentarios\EnviarComentario;
use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Clinica\Ayuda\ComentariosDelUsuario;
use App\Clinica\Ayuda\Vistas\MisComentariosVista;
use App\Http\Controllers\Controller;
use App\Models\Comentario;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * **"Ayúdanos a mejorar"** (ROADMAP §9): el usuario nos manda un comentario y ve los suyos, con la respuesta
 * cuando la hay.
 *
 * Vive en `/clinica` —y no en `/admin`— porque quien escribe es el médico o su secretaría, y cada uno ve los
 * suyos: la bandeja compartida, la nuestra, es otra pantalla (`/admin/comentarios`).
 *
 * Desde O-25 el comentario puede llevar **hasta 3 imágenes** (una captura pegada del portapapeles o elegida de
 * un archivo). La validación de cada archivo es la de la política de archivos
 * (`ArchivosDeLosComentarios`): tipo **real**, extensión permitida y tope de 5 MB; acá sólo se valida la
 * cantidad y se manda todo a la Action, que guarda las filas y los archivos juntos.
 */
class AyudaController extends Controller
{
    /** La pantalla: el formulario y mis comentarios. */
    public function index(Request $request, ComentariosDelUsuario $comentarios)
    {
        return view('clinica.ayuda.index', [
            'vm' => new MisComentariosVista($comentarios->de($request->user())),
        ]);
    }

    /** Guarda un comentario nuevo, con sus imágenes si las trae. */
    public function guardar(Request $request, EnviarComentario $enviar)
    {
        $datos = $request->validate([
            'tipo' => ['required', 'string', 'in:' . implode(',', array_keys(Comentario::TIPOS))],
            // Un mínimo de 5 es para atajar el "ok" de un toque; el tope, para que no nos llegue un libro.
            'mensaje' => ['required', 'string', 'min:5', 'max:2000'],
            // La cantidad es de la pantalla; el tipo y el tamaño de cada archivo, de la política compartida.
            'imagenes' => ['nullable', 'array', 'max:' . Comentario::MAXIMO_IMAGENES],
            'imagenes.*' => ArchivosDeLosComentarios::reglas(),
        ], [
            'tipo.required' => 'Elegí qué nos querés contar.',
            'tipo.in' => 'Ese tipo de comentario no existe.',
            'mensaje.required' => 'Escribí tu comentario.',
            'mensaje.min' => 'Contanos un poco más (al menos 5 caracteres).',
            'mensaje.max' => 'El comentario es muy largo (hasta 2000 caracteres).',
            'imagenes.array' => 'No se pudieron leer las imágenes adjuntas. Probá de nuevo.',
            'imagenes.max' => 'Podés adjuntar hasta ' . Comentario::MAXIMO_IMAGENES . ' imágenes por comentario.',
            'imagenes.*.file' => 'Lo que adjuntaste no es una imagen.',
            'imagenes.*.max' => 'Cada imagen puede pesar hasta ' . ArchivosDeLosComentarios::textoMaximo() . '.',
        ]);

        // `$request->file()` devuelve `null` cuando no vino ningún adjunto: la Action recibe una lista vacía.
        $imagenes = array_values(array_filter((array) $request->file('imagenes', [])));

        try {
            $enviar->ejecutar($request->user(), $datos, 'web', $imagenes);
        } catch (RuntimeException $e) {
            // Un disco lleno o sin permisos es un problema del servidor, no de lo que escribió el usuario: se
            // dice sin lenguaje técnico, se deja lo escrito en el formulario y se registra para diagnosticarlo.
            report($e);

            return redirect()
                ->route('clinica.ayuda')
                ->withInput()
                ->with('error', 'No se pudieron guardar las imágenes en el servidor. Vuelve a intentarlo; si sigue igual, avísale al administrador.');
        }

        return redirect()
            ->route('clinica.ayuda')
            ->with('estado', '¡Gracias! Ya nos llegó tu comentario y te vamos a responder acá mismo.');
    }
}
