<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AutenticacionPorCorreo;
use App\Services\BajasDeCuenta;
use App\Services\CuentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * **El login web, uno solo**: correo y contraseña, sin preguntar si la persona es médico, paciente o
 * administrador. Lo que antes se elegía en la pantalla ahora lo resuelve
 * `AutenticacionPorCorreo` mirando las tres tablas donde puede vivir la cuenta.
 *
 * A dónde entra lo decide `User::rutaDeEntrada()`: directo a su área si tiene una sola, o a
 * `/elegir-panel` si la cuenta puede trabajar en más de una (por ejemplo, un médico que además
 * administra).
 */
class CustomLoginController extends Controller
{
    public function login(Request $request, AutenticacionPorCorreo $autenticacion)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = $autenticacion->autenticar((string) $request->input('email'), (string) $request->input('password'));

        if (! $user) {
            // Un solo mensaje para los dos casos (correo inexistente y clave equivocada): no se revela
            // qué correos están registrados.
            throw ValidationException::withMessages([
                'email' => ['El correo o la contraseña no son correctos.'],
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // **Iniciar sesión cancela la baja programada** (decisión del usuario, 2026-10-30): si la persona
        // sigue entrando, es que no quiere que su cuenta desaparezca. El otro camino de cancelación es el
        // enlace del correo (ver `BajasDeCuenta`).
        app(BajasDeCuenta::class)->cancelarPorInicioDeSesion($user);

        // Acaba de escribir su clave temporal: el cambio obligatorio no se la vuelve a pedir.
        if ($user->must_change_password) {
            $request->session()->put(CuentaService::SESION_CLAVE_TEMPORAL, $user->id);
        }

        // `intended` primero: si la persona venía de una página protegida, se sigue por ahí (ese es el
        // único caso en que no se pregunta el área, porque ya la eligió al pedir la página).
        return redirect()->intended(route($user->rutaDeEntrada()));
    }
}
