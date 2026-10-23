<?php

namespace App\Http\Controllers\Api;

use App\Escritorio\Canal\CanalDelEscritorio;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **El canal de publicación del escritorio.**
 *
 * Lo público (lo que usa el launcher del consultorio, sin credencial):
 *
 *   GET /api/escritorio/canal          -> el manifiesto vigente, texto plano
 *   GET /api/escritorio/canal/{ruta}   -> un archivo de esa versión
 *   GET /api/escritorio/launcher       -> el propio launcher (para reenviarlo)
 *
 * Lo privado (publicar, con `X-API-KEY` = `ESCRITORIO_CANAL_KEY`):
 *
 *   POST /api/escritorio/publicar/iniciar
 *   POST /api/escritorio/publicar/archivo     (multipart, un archivo por request)
 *   POST /api/escritorio/publicar/cerrar
 *   POST /api/escritorio/publicar/launcher
 *
 * El que publica es `php artisan escritorio:publicar`.
 */
class CanalEscritorioController extends Controller
{
    private const SIN_CACHE = [
        'Content-Type'  => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
    ];

    /** El manifiesto vigente. 404 mientras no haya ninguna versión publicada. */
    public function manifiesto(CanalDelEscritorio $canal): Response
    {
        $texto = $canal->textoManifiesto();

        abort_if($texto === null, 404, 'Todavía no hay ninguna versión publicada.');

        return response($texto, 200, self::SIN_CACHE);
    }

    /** Un archivo **listado en el manifiesto vigente**. Cualquier otra ruta es 404. */
    public function archivo(CanalDelEscritorio $canal, string $ruta): StreamedResponse
    {
        $path = $canal->archivoPublicado($ruta);

        abort_if($path === null, 404);

        return Storage::disk($canal->disco())->response($path);
    }

    /** El launcher publicado (`instalar.exe`), para volver a mandarlo sin compilarlo. */
    public function launcher(CanalDelEscritorio $canal): StreamedResponse
    {
        $disco = Storage::disk($canal->disco());

        abort_unless($disco->exists($canal->rutaLauncher()), 404);

        return $disco->response($canal->rutaLauncher());
    }

    /** Arranca (o retoma) una versión. Los archivos que no cambiaron se reutilizan. */
    public function iniciar(Request $request, CanalDelEscritorio $canal)
    {
        $datos = $request->validate([
            'version'         => ['required', 'string', 'regex:' . CanalDelEscritorio::PATRON_VERSION],
            'apps'            => ['sometimes', 'array', 'max:20'],
            'reutilizar'      => ['sometimes', 'array'],
            'reutilizar.*'    => ['string', 'regex:' . CanalDelEscritorio::PATRON_SHA256],
        ]);

        $canal->iniciar($datos['version'], $datos['reutilizar'] ?? []);

        return response()->json(['ok' => true, 'version' => $datos['version']]);
    }

    /** Sube un archivo. El SHA-256 se comprueba contra lo recibido, no se confía en el cliente. */
    public function subirArchivo(Request $request, CanalDelEscritorio $canal)
    {
        $datos = $request->validate([
            'version' => ['required', 'string', 'regex:' . CanalDelEscritorio::PATRON_VERSION],
            'ruta'    => ['required', 'string', 'max:200'],
            'sha256'  => ['required', 'string', 'regex:' . CanalDelEscritorio::PATRON_SHA256],
            'archivo' => ['required', 'file', 'max:' . $this->topeEnKb()],
        ]);

        abort_unless($canal->rutaValida($datos['ruta']), 422, 'La ruta no es válida.');

        $contenido = (string) file_get_contents($request->file('archivo')->getRealPath());
        $sha = hash('sha256', $contenido);

        if (! hash_equals($datos['sha256'], $sha)) {
            return response()->json([
                'ok'     => false,
                'error'  => 'hash_no_coincide',
                'sha256' => $sha,
            ], 422);
        }

        $canal->guardarArchivo($datos['version'], $datos['ruta'], $contenido);

        return response()->json([
            'ok'     => true,
            'ruta'   => $datos['ruta'],
            'sha256' => $sha,
            'bytes'  => strlen($contenido),
        ]);
    }

    /** Cierra la versión y publica el manifiesto. Hasta acá, los consultorios ven la anterior. */
    public function cerrar(Request $request, CanalDelEscritorio $canal): Response
    {
        $datos = $request->validate([
            'version' => ['required', 'string', 'regex:' . CanalDelEscritorio::PATRON_VERSION],
            'apps'    => ['required', 'array', 'min:1', 'max:20'],
            'apps.*'  => ['string', 'regex:/^[A-Za-z0-9._-]+\.exe$/'],
        ]);

        $texto = $canal->cerrar($datos['version'], $datos['apps']);

        return response($texto, 200, self::SIN_CACHE);
    }

    /** Publica el launcher (no viaja en el manifiesto: no se auto-actualiza). */
    public function subirLauncher(Request $request, CanalDelEscritorio $canal)
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:' . $this->topeEnKb()],
        ]);

        $contenido = (string) file_get_contents($request->file('archivo')->getRealPath());

        $canal->guardarLauncher($contenido);

        return response()->json([
            'ok'     => true,
            'sha256' => hash('sha256', $contenido),
            'bytes'  => strlen($contenido),
        ]);
    }

    private function topeEnKb(): int
    {
        return max(1, (int) ceil(((int) config('escritorio.max_bytes', 67108864)) / 1024));
    }
}
