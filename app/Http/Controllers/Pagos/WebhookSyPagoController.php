<?php

namespace App\Http\Controllers\Pagos;

use App\Http\Controllers\Controller;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Pagos\Centavos;
use App\Pagos\Cobros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * **El webhook de SyPago** (`POST /api/pagos/sypago/{token}`).
 *
 * SyPago no firma el payload en el ejemplo funcional, así que la autenticación es el **token secreto por
 * método** que va en la URL (se genera solo al crear el método). Además:
 *  - se busca el pago por `internal_id` y se comprueba que sea de **ese** método;
 *  - se compara el monto del aviso con el del pago (si no coincide, no se aprueba);
 *  - se delega en `Cobros::conciliar()`, que **reconsulta** la transacción a SyPago; el payload sólo se
 *    usa como respaldo si la consulta falla.
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
}
