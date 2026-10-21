<?php

namespace App\Http\Controllers\Pagos;

use App\Http\Controllers\Controller;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Pagos\Centavos;
use App\Pagos\ClienteSyPago;
use App\Pagos\Cobros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * **El webhook de SyPago** (`POST /api/pagos/sypago/{token}`).
 *
 * La autenticación es el **token secreto por método** que va en la URL (se genera solo al crear el método).
 * Además, SyPago **firma** cada notificación: `X-Signature` (ECDSA en Base64) sobre
 * `"{payload crudo}.{X-Signature-Nonce}.{operation_secret}"`, con la clave pública de `GET user/key`.
 *  - se busca el pago por `internal_id` y se comprueba que sea de **ese** método;
 *  - se verifica la firma (si ya tenemos el `operation_secret`; si el aviso llegó antes de guardarlo,
 *    se procesa igual porque el payload no se cree: se reconsulta a SyPago);
 *  - se compara el monto del aviso con el del pago;
 *  - se delega en `Cobros::conciliar()`, que **reconsulta** la transacción; el payload sólo se usa como
 *    respaldo si la consulta falla.
 *
 * Siempre responde 200 cuando el aviso es válido (aunque el pago esté rechazado o en proceso): un 4xx/5xx
 * hace que SyPago reintente el mismo aviso.
 */
class WebhookSyPagoController extends Controller
{
    public function handle(Request $request, string $token, Cobros $cobros): JsonResponse
    {
        $metodo = MetodoPago::where('webhook_token', $token)
            ->where('pasarela', 'sypago')
            ->first();

        if (! $metodo) {
            abort(404);
        }

        $validador = Validator::make($request->all(), [
            'internal_id' => 'required|string|max:64',
            'status' => 'required|string|max:10',
            'transaction_id' => 'nullable|string|max:100',
            'ref_ibp' => 'nullable|string|max:100',
            'amount' => 'required|array',
            'amount.amt' => 'required',
            'rejected_code' => 'nullable|string|max:10',
            'message' => 'nullable|string|max:255',
        ]);

        if ($validador->fails()) {
            return response()->json(['ok' => false, 'mensaje' => 'Aviso incompleto.', 'errores' => $validador->errors()], 422);
        }

        $datos = $validador->validated();

        $pago = Pago::where('referencia', $datos['internal_id'])->first();
        if (! $pago || (int) $pago->metodo_pago_id !== (int) $metodo->id) {
            return response()->json(['ok' => false, 'mensaje' => 'Pago no encontrado.'], 404);
        }

        // Firma de SyPago: si ya guardamos el operation_secret, es obligatoria.
        $firma = (string) $request->header('X-Signature', '');
        $nonce = (string) $request->header('X-Signature-Nonce', '');

        if (trim((string) $pago->operation_secret) !== '') {
            if ($firma === '' || $nonce === ''
                || ! $this->firmaValida($metodo, $pago, $request->getContent(), $nonce, $firma)) {
                $pago->registrarEvento(
                    PagoEvento::ORIGEN_WEBHOOK,
                    'firma_invalida',
                    ['nonce' => $nonce],
                    $pago->estado,
                    $request->ip()
                );

                return response()->json(['ok' => false, 'mensaje' => 'Firma inválida.'], 403);
            }
        } else {
            // Carrera: el webhook llegó antes de persistir la respuesta del débito. No se cree el payload:
            // se sigue con la reconsulta, que es la fuente de verdad.
            Log::warning('Webhook SyPago sin operation_secret todavía; se procesa con reconsulta.', [
                'pago' => $pago->id,
            ]);
        }

        // El monto del aviso tiene que ser el del pago: si no, no se aprueba nada.
        if (isset($datos['amount']['amt'])) {
            $montoAviso = Centavos::deDecimal((string) $datos['amount']['amt']);
            if ($montoAviso !== (int) $pago->monto_e2) {
                Log::warning('Webhook SyPago con monto distinto al del pago', [
                    'pago' => $pago->id,
                    'esperado_e2' => $pago->monto_e2,
                    'aviso_e2' => $montoAviso,
                ]);
                $pago->registrarEvento(PagoEvento::ORIGEN_WEBHOOK, 'monto_no_coincide', $datos, $pago->estado, $request->ip());

                return response()->json(['ok' => false, 'mensaje' => 'El monto no coincide con el del pago.'], 422);
            }
        }

        $pago->registrarEvento(PagoEvento::ORIGEN_WEBHOOK, 'webhook_recibido', $datos, $pago->estado, $request->ip());

        $resultado = $cobros->conciliar($pago, PagoEvento::ORIGEN_WEBHOOK, $datos);

        return response()->json([
            'ok' => $resultado->ok,
            'estado' => $resultado->estado(),
            'mensaje' => $resultado->mensaje,
        ]);
    }

    /**
     * Verifica la firma ECDSA de SyPago sobre `"{payload}.{nonce}.{operation_secret}"` con SHA-256.
     *
     * SyPago puede mandar la firma en DER (lo que espera `openssl_verify`) o en el formato crudo de 64
     * bytes (`r || s`); si la primera verificación falla y el largo es 64, se convierte a DER y se reintenta.
     */
    private function firmaValida(
        MetodoPago $metodo,
        Pago $pago,
        string $payload,
        string $nonce,
        string $firma
    ): bool {
        $secreto = trim((string) $pago->operation_secret);
        if ($secreto === '' || $nonce === '' || $firma === '') {
            return false;
        }

        $binaria = base64_decode($firma, true);
        if ($binaria === false || $binaria === '') {
            return false;
        }

        try {
            $publica = ClienteSyPago::para($metodo)->clavePublica();
        } catch (\Throwable $e) {
            Log::error('No se pudo obtener la clave pública de SyPago para verificar el webhook.', [
                'pago' => $pago->id,
                'mensaje' => $e->getMessage(),
            ]);

            return false;
        }

        $stringToSign = $payload . '.' . $nonce . '.' . $secreto;

        if (openssl_verify($stringToSign, $binaria, $publica, OPENSSL_ALGO_SHA256) === 1) {
            return true;
        }

        if (strlen($binaria) === 64) {
            return openssl_verify($stringToSign, $this->rawToDer($binaria), $publica, OPENSSL_ALGO_SHA256) === 1;
        }

        return false;
    }

    /** Convierte una firma ECDSA cruda (`r || s`, 64 bytes) al DER que entiende OpenSSL. */
    private function rawToDer(string $cruda): string
    {
        $r = ltrim(substr($cruda, 0, 32), "\0");
        $s = ltrim(substr($cruda, 32), "\0");

        $r = $r === '' ? "\0" : $r;
        $s = $s === '' ? "\0" : $s;

        if (ord($r[0]) > 0x7f) {
            $r = "\0" . $r;
        }
        if (ord($s[0]) > 0x7f) {
            $s = "\0" . $s;
        }

        $r = "\x02" . chr(strlen($r)) . $r;
        $s = "\x02" . chr(strlen($s)) . $s;
        $secuencia = $r . $s;

        return "\x30" . chr(strlen($secuencia)) . $secuencia;
    }
}
