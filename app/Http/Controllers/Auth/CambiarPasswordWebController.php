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
        return view('auth.cambiar-password', [
            'obligatorio'     => (bool) $request->user()->must_change_password,
            'pideClaveActual' => ! $this->recienVerificada($request),
        ]);
    }

    /**
     * La clave es temporal y esta sesión nació de un login con ella (`CustomLoginController`): la persona
     * acaba de escribirla, así que no se le vuelve a pedir. Un acceso por «recordarme» no la trae.
     */
    private function recienVerificada(Request $request): bool
    {
        $user = $request->user();

        return $user->must_change_password
            && $request->session()->get(CuentaService::SESION_CLAVE_TEMPORAL) === $user->id;
    }

    public function actualizar(Request $request, CuentaService $cuentas)
    {
        $exento = $this->recienVerificada($request);

        $datos = $request->validate([
            'password_actual' => $exento ? ['nullable', 'string'] : ['required', 'string'],
            'password_nueva'  => ['required', 'string', 'confirmed', 'min:' . CuentaService::LARGO_MINIMO_CLAVE],
        ], [
            'password_nueva.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password_nueva.min'       => 'La contraseña debe tener al menos ' . CuentaService::LARGO_MINIMO_CLAVE . ' caracteres.',
        ]);

        $user = $request->user();

        if (! $exento && ! Hash::check($datos['password_actual'], $user->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($datos['password_nueva'], $user->password)) {
            throw ValidationException::withMessages(['password_nueva' => 'La contraseña nueva debe ser distinta de la actual.']);
        }

        $cuentas->cambiarClave($user, $datos['password_nueva']);
        $request->session()->forget(CuentaService::SESION_CLAVE_TEMPORAL);

        // Se vuelve al área del usuario, la misma a la que entra al iniciar sesión: un médico con clave
        // temporal tiene que terminar en el consultorio, no en el panel de administración. Si la cuenta
        // puede trabajar en más de un área, se le pregunta (`rutaDeEntrada`).
        return redirect()->route($user->rutaDeEntrada())->with('status', 'Contraseña actualizada.');
    }
}
