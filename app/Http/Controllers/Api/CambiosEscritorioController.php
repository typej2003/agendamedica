<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncCarga;
use App\Services\SyncAuthService;
use App\Sync\Escritorio\CambiosEscritorio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sincronización incremental del escritorio PowerBuilder (Fase 2). Diseño en bridge/DISENO-FASE-2.md.
 *
 *   POST /api/sync/cambios/estado  {reg_medico}           ¿ya hizo la carga inicial? tablas a vigilar
 *   POST /api/sync/cambios/subir   {reg_medico, cambios}  aplica lo que cambió en el escritorio
 *
 * Respuestas planas: PowerBuilder 10 las lee como clave=valor (sync.exe --kv-out).
 */
class CambiosEscritorioController extends Controller
{
    public function __construct(private SyncAuthService $auth)
    {
    }

    /**
     * Lo que el escritorio necesita para arrancar: si la carga inicial está completa (recién ahí se
     * sincronizan cambios) y la lista de tablas en las que tiene que registrar cambios.
     */
    public function estado(Request $request): JsonResponse
    {
        [$regMedico, $error] = $this->medico($request);
        if ($error) {
            return $error;
        }

        $carga = SyncCarga::where('reg_medico', $regMedico)->first();

        return response()->json([
            'ok'     => true,
            'carga'  => $carga ? $carga->estado : 'ninguna',
            'tablas' => implode(',', config('sync_legado.tablas', [])),
            'ahora'  => now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    public function subir(Request $request, CambiosEscritorio $cambios): JsonResponse
    {
        [$regMedico, $error] = $this->medico($request);
        if ($error) {
            return $error;
        }

        $carga = SyncCarga::where('reg_medico', $regMedico)->first();
        if (! $carga || $carga->estado !== SyncCarga::COMPLETA) {
            return response()->json([
                'ok' => false, 'error' => 'sin_carga_inicial',
                'mensaje' => "El médico {$regMedico} todavía no completó la carga inicial: los cambios se sincronizan después.",
            ], 409);
        }

        $lista = $this->cuerpo($request)['cambios'] ?? null;
        if (! is_array($lista)) {
            return response()->json(['ok' => false, 'error' => 'parametros', 'mensaje' => 'Falta la lista de cambios.'], 422);
        }

        return response()->json($cambios->subir($carga, $lista));
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0:?string, 1:?JsonResponse} */
    private function medico(Request $request): array
    {
        $auth = $this->auth->validar($request);
        if ($auth['status'] !== SyncAuthService::OK) {
            return [null, $this->auth->respuestaError($auth)];
        }

        // Con credencial de equipo el médico lo manda la credencial, no el payload.
        $regMedico = $auth['reg_medico'] ?: trim((string) ($this->cuerpo($request)['reg_medico'] ?? ''));
        if ($regMedico === '') {
            return [null, response()->json(['ok' => false, 'error' => 'parametros', 'mensaje' => 'Falta reg_medico.'], 422)];
        }

        return [$regMedico, null];
    }

    /** JSON crudo: los middleware globales convierten "" en null (ver CargaInicialController). */
    private function cuerpo(Request $request): array
    {
        $datos = json_decode($request->getContent(), true);

        return is_array($datos) ? $datos : [];
    }
}
