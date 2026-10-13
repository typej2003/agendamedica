<?php

namespace App\Http\Controllers\Api;

use App\Actions\Imagenes\OmitirImagenDeLaPc;
use App\Actions\Imagenes\ResultadoDeImagenDeLaPc;
use App\Actions\Imagenes\SubirImagenDeLaPc;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Http\Controllers\Controller;
use App\Services\ServicioService;
use App\Services\SyncAuthService;
use App\Sync\ImagenesPc\ReferenciasDeImagenes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * **Subir a la nube las imágenes que viven en el disco de la PC del consultorio** (WEB-T.1). Los tres endpoints los usa la
 * herramienta `bridge/subir-imagenes` (Node, sin dependencias); no hay botón en GinecoReport.
 *
 *   POST /api/sync/imagenes/pendientes   {reg_medico?, cursor?, limite?, reintentar_omitidas?, tabla?}
 *   POST /api/sync/imagenes/subir        multipart: archivo + {reg_medico?, tabla, fila_id, columna, ruta_pc, sha256}
 *   POST /api/sync/imagenes/omitir       {reg_medico?, items: [{tabla, fila_id, columna, ruta_pc, motivo}]}
 *
 * Autentican **exactamente como el resto del sync** (`SyncAuthService`): `X-API-KEY` de transición (el `reg_medico` va en el
 * cuerpo) o `Authorization: Bearer` con la credencial por equipo, cuyo `reg_medico` manda: un equipo no puede tocar los
 * archivos de otro médico (403 `medico_ajeno`). El servicio vencido responde 402 como los demás. Las reglas están en las
 * Actions (`SubirImagenDeLaPc`, `OmitirImagenDeLaPc`) y la lectura en `ReferenciasDeImagenes`.
 *
 * Los códigos de error son **estables** (la herramienta los compara por texto): `parametros`, `medico_ajeno`,
 * `tabla_no_permitida`, `fila_inexistente`, `fila_cambio`, `archivo_ausente`, `archivo_invalido`, `demasiado_grande`,
 * `limite_del_servidor`, `tipo_no_permitido`, `sha256_no_coincide`, `error_guardando`.
 */
class ImagenesPcController extends Controller
{
    public function __construct(
        private SyncAuthService $auth,
        private ServicioService $servicio,
        private ReferenciasDeImagenes $referencias,
    ) {
    }

    public function pendientes(Request $request): JsonResponse
    {
        [$reg, $error] = $this->medico($request);
        if ($error) {
            return $error;
        }

        $limiteMaximo = (int) config('imagenes_pc.pagina_maxima', 1000);
        $limite = (int) ($request->input('limite') ?: config('imagenes_pc.pagina', 500));
        $limite = max(1, min($limite, $limiteMaximo));

        $tabla = $request->input('tabla');
        $cursor = $request->input('cursor');

        $pagina = $this->referencias->pagina(
            $reg,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $limite,
            filter_var($request->input('reintentar_omitidas'), FILTER_VALIDATE_BOOLEAN),
            is_string($tabla) && $tabla !== '' ? $tabla : null,
        );

        return response()->json([
            'ok' => true,
            'reg_medico' => $reg,
            'items' => $pagina['items'],
            'siguiente' => $pagina['siguiente'],
            'pendientes_total' => $pagina['pendientes_total'],
            'tope_bytes' => ArchivosClinicos::maximoBytes(),
        ]);
    }

    public function subir(Request $request): JsonResponse
    {
        // Un POST más grande que `post_max_size` llega SIN cuerpo ni archivos (PHP lo descarta entero): no hay ni siquiera
        // `reg_medico`. Se distingue de un pedido vacío por el Content-Length declarado.
        $declarado = (int) $request->server('CONTENT_LENGTH', 0);
        if ($declarado > 0 && $request->all() === [] && $request->allFiles() === []) {
            return response()->json([
                'ok' => false, 'error' => 'limite_del_servidor',
                'mensaje' => 'El servidor descartó el envío por su tamaño (post_max_size).',
            ], 413);
        }

        [$reg, $error] = $this->medico($request);
        if ($error) {
            return $error;
        }

        $tabla = $request->input('tabla');
        $columna = $request->input('columna');
        $filaId = $request->input('fila_id');
        $rutaPc = $request->input('ruta_pc');
        $sha256 = $request->input('sha256');

        if (! is_string($tabla) || ! is_string($columna) || ! is_string($rutaPc) || $rutaPc === ''
            || ! is_numeric($filaId) || (int) $filaId < 1 || ! is_string($sha256) || ! preg_match('/^[A-Fa-f0-9]{64}$/', $sha256)) {
            return $this->parametros('Faltan tabla, fila_id, columna, ruta_pc o un sha256 válido.');
        }

        try {
            $resultado = app(SubirImagenDeLaPc::class)->ejecutar(
                $reg, $tabla, (int) $filaId, $columna, $rutaPc, $sha256, $request->file('archivo'), $this->equipo($request),
            );
        } catch (Throwable $e) {
            // Sin la ruta del archivo ni del sistema en el mensaje: sólo qué falló.
            Log::error('Imágenes de la PC: error guardando', [
                'reg_medico' => $reg, 'tabla' => $tabla, 'fila_id' => $filaId, 'columna' => $columna, 'error' => get_class($e),
            ]);

            return response()->json([
                'ok' => false, 'error' => 'error_guardando',
                'mensaje' => 'El servidor no pudo guardar la imagen. Se puede reintentar.',
            ], 500);
        }

        return $this->responder($resultado);
    }

    public function omitir(Request $request): JsonResponse
    {
        [$reg, $error] = $this->medico($request);
        if ($error) {
            return $error;
        }

        $items = $request->input('items');
        $maximo = (int) config('imagenes_pc.omitir_maximo', 200);
        if (! is_array($items) || $items === [] || count($items) > $maximo) {
            return $this->parametros("Mandá entre 1 y {$maximo} elementos en `items`.");
        }

        $omitir = app(OmitirImagenDeLaPc::class);
        $registradas = 0;
        $rechazadas = [];
        foreach (array_values($items) as $i => $item) {
            if (! is_array($item) || ! is_string($item['tabla'] ?? null) || ! is_string($item['columna'] ?? null)
                || ! is_numeric($item['fila_id'] ?? null) || ! is_string($item['ruta_pc'] ?? null) || $item['ruta_pc'] === '') {
                $rechazadas[] = ['indice' => $i, 'error' => 'parametros'];
                continue;
            }

            $r = $omitir->ejecutar(
                $reg, $item['tabla'], (int) $item['fila_id'], $item['columna'], $item['ruta_pc'],
                is_string($item['motivo'] ?? null) ? $item['motivo'] : 'otro', $this->equipo($request),
            );

            if ($r->ok) {
                $registradas++;
            } else {
                $rechazadas[] = ['indice' => $i, 'error' => $r->codigo];
            }
        }

        return response()->json(['ok' => true, 'registradas' => $registradas, 'rechazadas' => $rechazadas]);
    }

    /**
     * El médico de la petición: el de la credencial (y, si el cuerpo trae otro, 403) o el del cuerpo con la clave estática.
     *
     * @return array{0:?string,1:?JsonResponse}
     */
    private function medico(Request $request): array
    {
        $auth = $this->auth->validar($request);
        if ($auth['status'] !== SyncAuthService::OK) {
            return [null, $this->auth->respuestaError($auth)];
        }

        $delCuerpo = trim((string) $request->input('reg_medico', ''));

        if ($auth['reg_medico']) {
            if ($delCuerpo !== '' && $delCuerpo !== $auth['reg_medico']) {
                return [null, response()->json([
                    'ok' => false, 'error' => 'medico_ajeno', 'mensaje' => 'La credencial de este equipo es de otro médico.',
                ], 403)];
            }
            $reg = (string) $auth['reg_medico'];
        } else {
            $reg = $delCuerpo;
        }

        if ($reg === '') {
            return [null, $this->parametros('Falta reg_medico.')];
        }

        if ($bloqueo = $this->servicio->bloqueo($this->servicio->estado($reg))) {
            return [null, $bloqueo];
        }

        return [$reg, null];
    }

    private function responder(ResultadoDeImagenDeLaPc $resultado): JsonResponse
    {
        return response()->json($resultado->aArreglo(), $resultado->http);
    }

    private function parametros(string $mensaje): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'parametros', 'mensaje' => $mensaje], 422);
    }

    private function equipo(Request $request): ?string
    {
        $equipo = $request->header('X-Equipo');

        return is_string($equipo) && $equipo !== '' ? $equipo : null;
    }
}
