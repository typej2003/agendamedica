<?php

namespace App\Http\Controllers\Api;

use App\Actions\Comentarios\EnviarComentario;
use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Clinica\Ayuda\ComentariosDelUsuario;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComentarioResource;
use App\Models\Comentario;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * **"Ayúdanos a mejorar"** desde el app móvil (ROADMAP §9): mandar un comentario —un error, una sugerencia o
 * algo que le falta, con hasta 3 capturas— y ver los propios con la respuesta.
 *
 * Es la versión de bolsillo de `/clinica/ayuda` y comparte todo lo que importa con ella:
 *
 *  - **La regla de qué queda guardado** (de quién es, con qué rol, con qué contexto y con qué imágenes) es la
 *    Action `EnviarComentario`, no este controlador. Desde el principio se escribió pensando en este endpoint.
 *  - **La política de los archivos** es `ArchivosDeLosComentarios` (el perfil de comentario de
 *    `ArchivosClinicos`): 5 MB, PNG/JPG/WEBP, tipo real. Acá sólo se valida la cantidad, igual que en la web.
 *  - **La puerta a la captura** es la misma: `ImagenDelComentarioController::delAutor`, que sólo sirve las
 *    imágenes del comentario de quien pide (ruta en `routes/api.php`).
 *
 * **Pide conexión y no pasa por `sync-app-data`**, como el alta de motivos y el aviso de una cita: un comentario
 * es un envío único y cuyo resultado nos importa (es la única forma de que el usuario vea nuestra respuesta); la
 * cola de `pending_changes` es para las tablas espejo del legado. Si no hay señal, el app avisa y conserva el
 * texto escrito.
 */
class ComentarioController extends Controller
{
    /** De dónde salió el comentario (`comentarios.origen`): el app, no la web. */
    private const ORIGEN = 'app';

    /** Los comentarios de **esta cuenta**, de los más nuevos a los más viejos, con sus capturas. */
    public function index(Request $request, ComentariosDelUsuario $comentarios)
    {
        return ComentarioResource::collection($comentarios->de($request->user()));
    }

    /** Guarda un comentario nuevo, con sus capturas si las trae. */
    public function guardar(Request $request, EnviarComentario $enviar)
    {
        // Las mismas reglas y los mismos textos que la web (`AyudaController::guardar`): es el mismo dato y la
        // misma persona, y lo que el app muestra es el `errors` del servidor.
        $datos = $request->validate([
            'tipo' => ['required', 'string', 'in:' . implode(',', array_keys(Comentario::TIPOS))],
            // Un mínimo de 5 ataja el "ok" de un toque; el tope, para que no nos llegue un libro.
            'mensaje' => ['required', 'string', 'min:5', 'max:2000'],
            // El contexto del app (versión, pantalla) llega como cadena JSON dentro del formulario; su contenido
            // lo interpreta `contexto()`, que no rechaza el comentario si viene mal.
            'contexto' => ['nullable'],
            // **De dónde salió** (2026-10-20): `android`, `ios`, `windows`, `macos`, `linux` o `web`. Es
            // informativa, como el contexto —un valor raro se descarta y el comentario entra igual—, así que no
            // lleva `in:`; quien la normaliza es `Comentario::normalizarPlataforma`.
            'plataforma' => ['nullable', 'string', 'max:20'],
            // La cantidad es de la pantalla; el tipo y el tamaño de cada archivo, de la política compartida.
            'imagenes' => ['nullable', 'array', 'max:' . Comentario::MAXIMO_IMAGENES],
            'imagenes.*' => ArchivosDeLosComentarios::reglas(),
        ], [
            'tipo.required' => 'Elige qué nos quieres contar.',
            'tipo.in' => 'Ese tipo de comentario no existe.',
            'mensaje.required' => 'Escribe tu comentario.',
            'mensaje.min' => 'Contanos un poco más (al menos 5 caracteres).',
            'mensaje.max' => 'El comentario es muy largo (hasta 2000 caracteres).',
            'imagenes.array' => 'No se pudieron leer las imágenes adjuntas. Prueba de nuevo.',
            'imagenes.max' => 'Puedes adjuntar hasta ' . Comentario::MAXIMO_IMAGENES . ' imágenes por comentario.',
            'imagenes.*.file' => 'Lo que adjuntaste no es una imagen.',
            'imagenes.*.max' => 'Cada imagen puede pesar hasta ' . ArchivosDeLosComentarios::textoMaximo() . '.',
        ]);

        // `$request->file('imagenes')` es `null` cuando no vino ninguna captura: la Action recibe una lista vacía.
        $imagenes = array_values(array_filter((array) $request->file('imagenes', [])));

        $contexto = $this->contexto($request->input('contexto'));

        try {
            $comentario = $enviar->ejecutar($request->user(), [
                'tipo' => $datos['tipo'],
                'mensaje' => $datos['mensaje'],
                'contexto' => $contexto,
                // La plataforma viene aparte desde la 0.8 del app; las versiones anteriores sólo la traían dentro
                // del contexto, así que se usa de respaldo (la bandeja la muestra igual).
                'plataforma' => $datos['plataforma'] ?? ($contexto['plataforma'] ?? null),
            ], self::ORIGEN, $imagenes);
        } catch (RuntimeException $e) {
            // Un disco lleno o sin permisos es un problema del servidor: se dice sin lenguaje técnico —el app
            // muestra el `message`— y se registra para diagnosticarlo, como en la web.
            report($e);

            return response()->json([
                'message' => 'No se pudieron guardar las imágenes en el servidor. Vuelve a intentarlo; si sigue igual, avísale al administrador.',
            ], 500);
        }

        return (new ComentarioResource($comentario->load('imagenes')))->response()->setStatusCode(201);
    }

    /**
     * El **contexto** que nos manda el app —versión y pantalla, para no tener que preguntar—: viaja como una
     * cadena JSON dentro del formulario, porque el multipart no lleva objetos anidados.
     *
     * Es **informativo**: si viene mal formado se descarta en vez de rechazar el comentario. Lo que nos tiene que
     * llegar es lo que escribió el usuario; el contexto ahorra preguntas, no es un requisito.
     *
     * @return array<string,mixed>|null
     */
    private function contexto(mixed $crudo): ?array
    {
        if (is_array($crudo)) {
            return $crudo;
        }

        if (! is_string($crudo) || trim($crudo) === '') {
            return null;
        }

        $datos = json_decode($crudo, true);

        return is_array($datos) ? $datos : null;
    }
}
