<?php

namespace App\Services;

use App\Models\SyncCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Autenticación de los endpoints de subida del escritorio PowerBuilder.
 *
 * Acepta DOS credenciales, para poder migrar sin cortar el sync que hoy funciona:
 *
 *  1. `X-API-KEY` — la clave estática que el legado tiene hardcodeada en el `.pbl`
 *     (`ls_api_key`). Sigue vigente mientras dure la transición.
 *  2. `Authorization: Bearer ddr_sync_...` — la credencial por equipo, con vencimiento y
 *     revocación (ver `SyncCredential` y la migración `create_sync_credentials_table`).
 *
 * POR QUÉ ESTA CLASE EXISTE Y NO SE USA EL GUARD DE SANCTUM:
 * los endpoints de sync viven fuera de `auth:sanctum` (routes/api.php), y `config/sanctum.php`
 * no existe en este proyecto, así que un token de Sanctum acá no vencería nunca. La expiración
 * y la revocación se verifican EXPLÍCITAMENTE acá — que es lo único que las hace reales.
 */
class SyncAuthService
{
    /** Resultado: autenticado OK. */
    public const OK = 'ok';
    /** Resultado: falta la credencial. */
    public const FALTA = 'falta';
    /** Resultado: la credencial no existe, está vencida o revocada. */
    public const INVALIDA = 'invalida';
    /** Resultado: el equipo no coincide con el que tiene atada la credencial. */
    public const EQUIPO_DISTINTO = 'equipo_distinto';

    /**
     * Valida la petición.
     *
     * @return array{status:string, credential:?SyncCredential, reg_medico:?string, message:string, http:int}
     */
    public function validar(Request $request): array
    {
        $token = $this->tokenDelRequest($request);

        // --- 1) Credencial por equipo (Bearer) ---
        if ($token !== null) {
            return $this->validarToken($request, $token);
        }

        // --- 2) Clave estática de transición (X-API-KEY) ---
        $apiKey = $request->header('X-API-KEY');
        if (is_string($apiKey) && $apiKey !== '') {
            $esperada = (string) config('app.sync_api_key', 'MiClaveSecreta123!');
            if (hash_equals($esperada, $apiKey)) {
                return [
                    'status'      => self::OK,
                    'credential'  => null,
                    'reg_medico'  => null, // lo toma del payload, como hoy
                    'message'     => 'Autenticado con X-API-KEY (transición)',
                    'http'        => 200,
                ];
            }

            Log::warning('Sync: X-API-KEY inválida', ['ip' => $request->ip()]);

            return [
                'status'     => self::INVALIDA,
                'credential' => null,
                'reg_medico' => null,
                'message'    => 'API Key inválida.',
                'http'       => 401,
            ];
        }

        return [
            'status'     => self::FALTA,
            'credential' => null,
            'reg_medico' => null,
            'message'    => 'Falta la credencial de sincronización (X-API-KEY o Authorization: Bearer).',
            'http'       => 401,
        ];
    }

    private function tokenDelRequest(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (is_string($header) && stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }

    private function validarToken(Request $request, string $token): array
    {
        $credential = SyncCredential::where('token_hash', SyncCredential::hashToken($token))->first();

        if (! $credential) {
            Log::warning('Sync: token desconocido', ['ip' => $request->ip()]);

            return [
                'status'     => self::INVALIDA,
                'credential' => null,
                'reg_medico' => null,
                'message'    => 'Credencial de sincronización inválida.',
                'http'       => 401,
            ];
        }

        if ($credential->estaRevocada()) {
            Log::warning('Sync: credencial revocada', [
                'id'     => $credential->id,
                'equipo' => $credential->machine_label,
            ]);

            return [
                'status'     => self::INVALIDA,
                'credential' => null,
                'reg_medico' => null,
                'message'    => 'La credencial de este equipo fue revocada. Emita una nueva con '
                    . '`php artisan sync:credencial emitir`.',
                'http'       => 401,
            ];
        }

        if ($credential->estaVencida()) {
            Log::warning('Sync: credencial vencida', [
                'id'     => $credential->id,
                'equipo' => $credential->machine_label,
                'vencio' => $credential->expires_at?->toIso8601String(),
            ]);

            return [
                'status'     => self::INVALIDA,
                'credential' => null,
                'reg_medico' => null,
                'message'    => 'La credencial de este equipo venció el '
                    . $credential->expires_at?->format('d/m/Y') . '. Emita una nueva con '
                    . '`php artisan sync:credencial emitir`.',
                'http'       => 401,
            ];
        }

        // Atadura al equipo: SOLO se exige si la credencial fue emitida con --atar-equipo.
        // Por defecto el hostname se registra como bitácora pero no bloquea (un renombre de PC
        // no debe dejar el consultorio sin sincronizar).
        $host = $request->header('X-Equipo') ?: $request->input('machine_host');

        if ($credential->bound_to_machine) {
            if (! is_string($host) || $host === '') {
                return [
                    'status'     => self::EQUIPO_DISTINTO,
                    'credential' => null,
                    'reg_medico' => null,
                    'message'    => 'Esta credencial está atada a un equipo y la petición no envió X-Equipo.',
                    'http'       => 401,
                ];
            }
            if (! hash_equals((string) $credential->machine_host, $host)) {
                Log::warning('Sync: credencial usada desde otro equipo', [
                    'id'       => $credential->id,
                    'esperado' => $credential->machine_host,
                    'recibido' => $host,
                    'ip'       => $request->ip(),
                ]);

                return [
                    'status'     => self::EQUIPO_DISTINTO,
                    'credential' => null,
                    'reg_medico' => null,
                    'message'    => 'La credencial no corresponde a este equipo.',
                    'http'       => 401,
                ];
            }
        } elseif (is_string($host) && $host !== '' && $host !== $credential->machine_host) {
            // Bitácora: el equipo cambió de nombre o se movió la credencial. No se bloquea,
            // pero queda registrado para poder investigar.
            Log::info('Sync: el hostname difiere del registrado (no bloquea)', [
                'id'       => $credential->id,
                'registrado' => $credential->machine_host,
                'recibido' => $host,
            ]);
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        return [
            'status'     => self::OK,
            'credential' => $credential,
            'reg_medico' => $credential->reg_medico,
            'message'    => 'Autenticado con credencial de equipo (' . $credential->machine_label . ')',
            'http'       => 200,
        ];
    }

    /**
     * Respuesta estándar para una autenticación fallida.
     * Se usa el mismo formato que ya devolvía el controlador (`status` = 'error') para no romper
     * a ningún consumidor existente.
     */
    public function respuestaError(array $resultado)
    {
        return response()->json([
            'status'  => 'error',
            'message' => $resultado['message'],
            'motivo'  => $resultado['status'],
        ], $resultado['http']);
    }
}
