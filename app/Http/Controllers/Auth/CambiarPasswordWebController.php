<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\CuentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cambio de contraseña en la web. Es el destino de `EnsurePasswordChanged` cuando la clave es temporal
 * (primer inicio de sesión o reseteo), y también sirve para cambiarla por voluntad propia.
 *
 * Formulario común a propósito (sin Livewire): el middleware solo deja pasar esta ruta, y una acción de
 * Livewire iría por otra URL y quedaría redirigida.
 */
class CambiarPasswordWebController extends Controller
{
    public function formulario(Request $request)
    {
        return view('auth.cambiar-password', ['obligatorio' => (bool) $request->user()->must_change_password]);
    }

    public function actualizar(Request $request, CuentaService $cuentas)
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

        $cuentas->cambiarClave($user, $datos['password_nueva']);

        // Se vuelve al área del usuario, la misma a la que entra al iniciar sesión: un médico con clave
        // temporal tiene que terminar en el consultorio, no en el panel de administración.
        return redirect()->route($user->rutaDeInicio())->with('status', 'Contraseña actualizada.');
    }
}
