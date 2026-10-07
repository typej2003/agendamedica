<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncCarga;
use App\Services\CargaInicialService;
use App\Events\EscritorioSincronizo;
use App\Services\ServicioService;
use App\Services\SyncAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Carga inicial completa desde el escritorio PowerBuilder (botón "Sincronización completa").
 *
 *   POST /api/sync/carga-inicial/iniciar    {reg_medico, tablas: {tabla: filas}}
 *   POST /api/sync/carga-inicial/lote       {carga_id, tabla, desde, filas: [...]}
 *   POST /api/sync/carga-inicial/finalizar  {carga_id}
 *
 * Las respuestas son planas a propósito (sin objetos anidados salvo `tablas`): PowerBuilder 10 no
 * tiene parser de JSON y el puente (`sync.exe --kv-out`) se las pasa como líneas `clave=valor`.
 * Reglas y detalle en App\Services\CargaInicialService.
 */
class CargaInicialController extends Controller
{
    public function __construct(
        private SyncAuthService $auth,
        private CargaInicialService $cargas,
        private ServicioService $servicio
    ) {
    }

    public function iniciar(Request $request): JsonResponse
    {
        $auth = $this->auth->validar($request);
        if ($auth['status'] !== SyncAuthService::OK) {
            return $this->auth->respuestaError($auth);
        }

        $cuerpo = $this->cuerpo($request);

        // Con credencial de equipo el médico lo manda la credencial, no el payload.
        $regMedico = $auth['reg_medico'] ?: trim((string) ($cuerpo['reg_medico'] ?? ''));
        $tablas = $cuerpo['tablas'] ?? null;

        if ($regMedico === '' || ! is_array($tablas)) {
            return response()->json([
                'ok' => false, 'error' => 'parametros', 'mensaje' => 'Faltan reg_medico o tablas.',
            ], 422);
        }

        if ($bloqueo = $this->bloqueoDeServicio($regMedico)) {
            return $bloqueo;
        }

        $r = $this->cargas->iniciar($regMedico, $tablas);

        return response()->json($r['cuerpo'], $r['http']);
    }

    public function lote(Request $request): JsonResponse
    {
        [$carga, $error] = $this->cargaDelRequest($request);
        if ($error) {
            return $error;
        }

        $cuerpo = $this->cuerpo($request);
        $filas = $cuerpo['filas'] ?? null;
        if (! is_string($cuerpo['tabla'] ?? null) || ! is_numeric($cuerpo['desde'] ?? null) || ! is_array($filas)) {
            return response()->json([
                'ok' => false, 'error' => 'parametros', 'mensaje' => 'Faltan tabla, desde o filas.',
            ], 422);
        }

        try {
            $r = $this->cargas->recibirLote($carga, $cuerpo['tabla'], (int) $cuerpo['desde'], $filas);
        } catch (\Throwable $e) {
            // Con el motivo en `mensaje`, que es lo que PowerBuilder le muestra al usuario. El lote se
            // aplicó en una transacción: no quedó nada a medias y se puede reenviar.
            Log::error('Carga inicial: error guardando un lote', [
                'carga' => $carga->id, 'tabla' => $cuerpo['tabla'], 'desde' => $cuerpo['desde'], 'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false, 'error' => 'error_guardando',
                'mensaje' => 'El servidor no pudo guardar el lote de ' . $cuerpo['tabla'] . ': ' . mb_substr($e->getMessage(), 0, 400),
            ], 500);
        }

        return response()->json($r['cuerpo'], $r['http']);
    }

    public function finalizar(Request $request): JsonResponse
    {
        [$carga, $error] = $this->cargaDelRequest($request);
        if ($error) {
            return $error;
        }

        $r = $this->cargas->finalizar($carga);

        return response()->json($r['cuerpo'], $r['http']);
    }

    /**
     * El cuerpo JSON TAL COMO LLEGÓ. No se usa `$request->input()` porque los middleware globales
     * (TrimStrings, ConvertEmptyStringsToNull) lo alteran: un texto vacío del legado llegaba como
     * null y rompía las columnas NOT NULL (p. ej. `imagen_pacientes.imagen`), y los espacios se
     * perdían. Una carga inicial tiene que guardar los datos exactamente como están en el escritorio.
     */
    private function cuerpo(Request $request): array
    {
        $datos = json_decode($request->getContent(), true);

        return is_array($datos) ? $datos : [];
    }

    /** @return array{0:?SyncCarga, 1:?JsonResponse} */
    private function cargaDelRequest(Request $request): array
    {
        $auth = $this->auth->validar($request);
        if ($auth['status'] !== SyncAuthService::OK) {
            return [null, $this->auth->respuestaError($auth)];
        }

        $carga = SyncCarga::find((int) ($this->cuerpo($request)['carga_id'] ?? 0));
        if (! $carga) {
            return [null, response()->json([
                'ok' => false, 'error' => 'carga_inexistente', 'mensaje' => 'La carga indicada no existe.',
            ], 404)];
        }

        // Una credencial de equipo solo puede tocar la carga de SU médico.
        if ($auth['reg_medico'] && $auth['reg_medico'] !== $carga->reg_medico) {
            return [null, response()->json([
                'ok' => false, 'error' => 'carga_ajena', 'mensaje' => 'La carga pertenece a otro médico.',
            ], 403)];
        }

        if ($bloqueo = $this->bloqueoDeServicio($carga->reg_medico)) {
            return [null, $bloqueo];
        }

        return [$carga, null];
    }

    /**
     * Sin servicio vigente no se sube nada. Antes se avisa que el escritorio habló: quien sincroniza desde él
     * recibe su año de cortesía la primera vez (ver App\Listeners\OtorgarAnioPowerBuilder).
     */
    private function bloqueoDeServicio(string $regMedico): ?JsonResponse
    {
        EscritorioSincronizo::dispatch($regMedico);

        return $this->servicio->bloqueo($this->servicio->estado($regMedico));
    }
}
