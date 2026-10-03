<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;

class LoginAppController extends Controller
{
    /**
     * Autenticación rápida para la app móvil.
     *
     * Reglas (cuentas de acceso, decidido con José Rosales: `users` es la única tabla de acceso):
     *  - Si existe la fila en `users`, la clave se valida SOLO contra `users`. Antes se probaba `users` y, si
     *    fallaba, `medicos.password`: un reset parcial dejaba dos claves válidas a la vez.
     *  - Solo si NO hay fila en `users` se cae al legado (`medicos` / `pacientes`), y al usarlo se crea la
     *    cuenta con su `tipo`.
     *  - Una cuenta bloqueada (`is_active = false`) no entra: 403 `account_blocked`.
     *  - Si la clave es temporal (`must_change_password`) la respuesta lo avisa; el token solo sirve para
     *    `POST /app/cambiar-password` hasta que la cambie (ver EnsurePasswordChanged).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // 1. Capturar y limpiar datos
        $email = \trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        $user = null;
        $userType = null;

        // 2. Cuenta de acceso (`users`): administradores, médicos y pacientes con cuenta ya creada
        $cuenta = User::whereRaw('LOWER(email) = ?', [\mb_strtolower($email)])->first();
        if ($cuenta) {
            if ($password !== '' && Hash::check($password, $cuenta->password)) {
                $user = $cuenta;
                $userType = $this->tipoDeCuenta($cuenta);
            }
        } else {
            // 3. Legado: médico con clave en `medicos` y sin cuenta todavía
            $medico = Medico::where('email', $email)->first();
            if ($medico && $medico->password && Hash::check($password, $medico->password)) {
                $userType = 'Medico';
                $user = User::create([
                    'name'      => $medico->nombre ?? $medico->name ?? ('Dr. ' . ($medico->apellido ?? '')),
                    'email'     => $medico->email,
                    'password'  => $medico->password,
                    'tipo'      => User::TIPO_MEDICO,
                    'is_active' => (bool) ($medico->is_active ?? true),
                ]);
                $medico->user_id = $user->id;
                $medico->save();

                if (\method_exists($user, 'hasRole') && !$user->hasRole('Medico')) {
                    $user->assignRole('Medico');
                }
            }

            // 4. Legado: paciente con clave en `pacientes` y sin cuenta todavía
            if (!$user) {
                $paciente = Paciente::where('email', $email)->first();
                if ($paciente && $paciente->password && Hash::check($password, $paciente->password)) {
                    $userType = 'Paciente';
                    $user = User::create([
                        'name'     => $paciente->nombres ?? $paciente->name ?? ($paciente->apellidos ?? ''),
                        'email'    => $paciente->email,
                        'password' => $paciente->password,
                        'tipo'     => User::TIPO_PACIENTE,
                    ]);
                    $paciente->user_id = $user->id;
                    $paciente->save();

                    if (\method_exists($user, 'hasRole') && !$user->hasRole('Paciente')) {
                        $user->assignRole('Paciente');
                    }
                }
            }
        }

        // 5. Respuesta JSON liviana
        if ($user) {
            // Una cuenta bloqueada no recibe token. Se avisa recién con la clave correcta, para no revelar
            // qué correos existen.
            if ($user->is_active === false) {
                return \response()->json([
                    'code'    => 'account_blocked',
                    'message' => 'Tu cuenta está bloqueada. Comunícate con soporte.',
                ], 403);
            }

            try {
                // Token Sanctum
                $token = \method_exists($user, 'createToken')
                    ? $user->createToken('agenda-token')->plainTextToken
                    : \base64_encode(Str::random(40) . '|' . $user->id);

                // Roles y Permisos de Spatie
                $roles = \method_exists($user, 'getRoleNames') ? $user->getRoleNames() : \collect([$userType]);
                $permissions = \method_exists($user, 'getAllPermissions')
                    ? $user->getAllPermissions()->pluck('name')
                    : \collect([]);

                return \response()->json([
                    'access_token'         => $token,
                    'token_type'           => 'Bearer',
                    'user_type'            => $userType,
                    'must_change_password' => (bool) $user->must_change_password,
                    'user'         => [
                        'id'                   => $user->id,
                        'name'                 => $user->name,
                        'email'                => $user->email,
                        'roles'                => $roles,
                        'permissions'          => $permissions,
                        'must_change_password' => (bool) $user->must_change_password,
                    ],
                ], 200);

            } catch (\Exception $e) {
                return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
            }
        }

        return \response()->json(['message' => 'Credenciales incorrectas o usuario no registrado.'], 401);
    }

    /**
     * Etiqueta informativa de la cuenta. La app móvil no la usa; sale del `tipo` (antes toda cuenta de `users`
     * se etiquetaba "Root", médicos incluidos).
     */
    private function tipoDeCuenta(User $user): string
    {
        return match ($user->tipo) {
            User::TIPO_MEDICO        => 'Medico',
            User::TIPO_PACIENTE      => 'Paciente',
            User::TIPO_ADMINISTRADOR => 'Administrador',
            default                  => $user->esAdministrador() ? 'Administrador' : 'Medico',
        };
    }
}
