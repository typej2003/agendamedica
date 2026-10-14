<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Comentarios\EnviarComentario;
use App\Clinica\Ayuda\ComentariosDelUsuario;
use App\Clinica\Ayuda\Vistas\MisComentariosVista;
use App\Http\Controllers\Controller;
use App\Models\Comentario;
use Illuminate\Http\Request;

/**
 * **"Ayúdanos a mejorar"** (ROADMAP §9): el usuario nos manda un comentario y ve los suyos, con la respuesta
 * cuando la hay.
 *
 * Vive en `/clinica` —y no en `/admin`— porque quien escribe es el médico o su secretaría, y cada uno ve los
 * suyos: la bandeja compartida, la nuestra, es otra pantalla (`/admin/comentarios`).
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

    /** Guarda un comentario nuevo. */
    public function guardar(Request $request, EnviarComentario $enviar)
    {
        $datos = $request->validate([
            'tipo' => ['required', 'string', 'in:' . implode(',', array_keys(Comentario::TIPOS))],
            // Un mínimo de 5 es para atajar el "ok" de un toque; el tope, para que no nos llegue un libro.
            'mensaje' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'tipo.required' => 'Elegí qué nos querés contar.',
            'tipo.in' => 'Ese tipo de comentario no existe.',
            'mensaje.required' => 'Escribí tu comentario.',
            'mensaje.min' => 'Contanos un poco más (al menos 5 caracteres).',
            'mensaje.max' => 'El comentario es muy largo (hasta 2000 caracteres).',
        ]);

        $enviar->ejecutar($request->user(), $datos, 'web');

        return redirect()
            ->route('clinica.ayuda')
            ->with('estado', '¡Gracias! Ya nos llegó tu comentario y te vamos a responder acá mismo.');
    }
}
