<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CuentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /api/app/cambiar-password — la persona elige su contraseña.
 *
 * Es la única acción (junto con `GET /user`) que sigue abierta mientras la clave es temporal
 * (`must_change_password`, ver EnsurePasswordChanged). Sirve tanto para el primer inicio de sesión como para
 * el cambio voluntario.
 *
 *   { "password_actual": "...", "password_nueva": "...", "password_nueva_confirmation": "..." }
 *
 * `password_actual` no se pide (y si llega se ignora) cuando la clave es temporal y el token salió de ese
 * mismo login (`CuentaService::HABILIDAD_CLAVE_TEMPORAL`): la persona acaba de escribirla.
 *
 * Cierra las demás sesiones de la cuenta y conserva la actual.
 */
class CambiarPasswordController extends Controller
{
    public function cambiar(Request $request, CuentaService $cuentas): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        // Recién entró con la clave temporal (el token lo prueba): no se le vuelve a pedir.
        $exento = $user->must_change_password
            && $token instanceof PersonalAccessToken
            && in_array(CuentaService::HABILIDAD_CLAVE_TEMPORAL, $token->abilities ?? [], true);

        $datos = $request->validate([
            'password_actual' => $exento ? ['nullable', 'string'] : ['required', 'string'],
            'password_nueva'  => ['required', 'string', 'confirmed', 'min:' . CuentaService::LARGO_MINIMO_CLAVE],
        ], [
            'password_nueva.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password_nueva.min'       => 'La contraseña debe tener al menos ' . CuentaService::LARGO_MINIMO_CLAVE . ' caracteres.',
        ]);

        if (! $exento && ! Hash::check($datos['password_actual'], $user->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($datos['password_nueva'], $user->password)) {
            throw ValidationException::withMessages(['password_nueva' => 'La contraseña nueva debe ser distinta de la actual.']);
        }

        // Con un token real se conserva esa sesión; con uno transitorio (tests) no hay id que conservar.
        $cuentas->cambiarClave($user, $datos['password_nueva'], $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->json([
            'message'              => 'Contraseña actualizada.',
            'must_change_password' => false,
        ]);
    }
}
