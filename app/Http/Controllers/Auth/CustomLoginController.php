<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login web.
 *
 * Reglas (las mismas que `Api\LoginAppController`):
 *  - Los administradores NO tienen un acceso aparte: entran como médico (o paciente) y, si lo son, ven el
 *    botón "Modo Administrador" en el menú. El formulario ya no ofrece "Root / acceso al sistema" y un
 *    `user_type=Root` enviado a mano se ignora.
 *  - Si existe la fila en `users`, la clave se valida SOLO contra `users`. `user_type` (Paciente/Médico) queda
 *    para desambiguar cuentas legado que todavía no tienen fila en `users`.
 *  - Una cuenta bloqueada no entra. Una clave temporal manda a `/cambiar-password` (EnsurePasswordChanged).
 */
class CustomLoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'     => 'required|email',
            'password'  => 'required|string',
            'user_type' => 'nullable|string',
        ]);

        $email    = trim($request->email);
        $password = $request->password;
        $remember = $request->has('remember');

        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($user) {
            if (! Hash::check($password, $user->password)) {
                $this->credencialesIncorrectas();
            }
        } else {
            $user = $this->crearCuentaDesdeLegado($email, $password, (string) $request->user_type);
            if (! $user) {
                $this->credencialesIncorrectas();
            }
        }

        if ($user->is_active === false) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está bloqueada. Comunícate con soporte.'],
            ]);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Médico o paciente con clave en su tabla del legado y sin fila en `users`: se le crea la cuenta (mismo
     * hash ya encriptado) con su `tipo` y su rol. Solo se busca en la tabla que eligió en el formulario.
     */
    private function crearCuentaDesdeLegado(string $email, string $password, string $tipoElegido): ?User
    {
        if ($tipoElegido === 'Medico') {
            $medico = Medico::where('email', $email)->first();
            if (! $medico || ! $medico->password || ! Hash::check($password, $medico->password)) {
                return null;
            }

            $user = User::create([
                'name'      => $medico->nombre ?? $medico->name ?? 'Dr. ' . $medico->apellido,
                'email'     => $medico->email,
                'password'  => $medico->password,
                'tipo'      => User::TIPO_MEDICO,
                'is_active' => (bool) ($medico->is_active ?? true),
            ]);
            $medico->user_id = $user->id;
            $medico->save();
            $user->assignRole('Medico');

            return $user;
        }

        if ($tipoElegido === 'Paciente') {
            $paciente = Paciente::where('email', $email)->first();
            if (! $paciente || ! $paciente->password || ! Hash::check($password, $paciente->password)) {
                return null;
            }

            $user = User::create([
                'name'     => $paciente->nombres ?? $paciente->apellidos,
                'email'    => $paciente->email,
                'password' => $paciente->password,
                'tipo'     => User::TIPO_PACIENTE,
            ]);
            $paciente->user_id = $user->id;
            $paciente->save();
            $user->assignRole('Paciente');

            return $user;
        }

        return null;
    }

    /** Mensaje único: no revela si el correo existe. */
    private function credencialesIncorrectas(): void
    {
        throw ValidationException::withMessages([
            'password' => ['Las credenciales ingresadas son incorrectas.'],
        ]);
    }
}
