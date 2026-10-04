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
 * Cierra las demás sesiones de la cuenta y conserva la actual.
 */
class CambiarPasswordController extends Controller
{
    public function cambiar(Request $request, CuentaService $cuentas): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password_nueva'  => ['required', 'string', 'confirmed', 'min:' . CuentaService::LARGO_MINIMO_CLAVE],
        ], [
            'password_nueva.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password_nueva.min'       => 'La contraseña debe tener al menos ' . CuentaService::LARGO_MINIMO_CLAVE . ' caracteres.',
        ]);

        $user = $request->user();

        if (! Hash::check($datos['password_actual'], $user->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if ($datos['password_actual'] === $datos['password_nueva']) {
            throw ValidationException::withMessages(['password_nueva' => 'La contraseña nueva debe ser distinta de la actual.']);
        }

        // Con un token real se conserva esa sesión; con uno transitorio (tests) no hay id que conservar.
        $token = $user->currentAccessToken();
        $cuentas->cambiarClave($user, $datos['password_nueva'], $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->json([
            'message'              => 'Contraseña actualizada.',
            'must_change_password' => false,
        ]);
    }
}
