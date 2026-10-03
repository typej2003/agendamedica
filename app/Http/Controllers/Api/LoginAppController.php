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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // 1. Capturar y limpiar datos
        $email = \trim($request->input('email'));
        $password = $request->input('password');

        $user = null;
        $userType = null;

        // 2. Intentar autenticar contra la tabla `users` (Caso Root / Admin)
        $rootUser = User::where('email', $email)->first();
        if ($rootUser && Hash::check($password, $rootUser->password)) {
            $user = $rootUser;
            $userType = 'Root';
        }

        // 3. Caso Médico
        if (!$user) {
            $medico = Medico::where('email', $email)->first();
            if ($medico && Hash::check($password, $medico->password)) {
                $userType = 'Medico';

                // Buscar o sincronizar con la tabla `users`
                $user = User::where('email', $email)->first();
                if (!$user) {
                    $user = User::create([
                        'name'     => $medico->nombre ?? $medico->name ?? ('Dr. ' . ($medico->apellido ?? '')),
                        'email'    => $medico->email,
                        'password' => $medico->password,
                    ]);
                    $medico->user_id = $user->id;
                    $medico->save();
                }

                // Asignación explícita del rol Spatie "Medico"
                if (\method_exists($user, 'hasRole') && !$user->hasRole('Medico')) {
                    $user->assignRole('Medico');
                }
            }
        }

        // 4. Caso Paciente
        if (!$user) {
            $paciente = Paciente::where('email', $email)->first();
            if ($paciente && Hash::check($password, $paciente->password)) {
                $userType = 'Paciente';

                // Buscar o sincronizar con la tabla `users`
                $user = User::where('email', $email)->first();
                if (!$user) {
                    $user = User::create([
                        'name'     => $paciente->nombres ?? $paciente->name ?? ($paciente->apellidos ?? ''),
                        'email'    => $paciente->email,
                        'password' => $paciente->password,
                    ]);
                    $paciente->user_id = $user->id;
                    $paciente->save();
                }

                // Asignación explícita del rol Spatie "Paciente"
                if (\method_exists($user, 'hasRole') && !$user->hasRole('Paciente')) {
                    $user->assignRole('Paciente');
                }
            }
        }

        // 5. Respuesta JSON liviana
        if ($user) {
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
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'user_type'    => $userType,
                    'user'         => [
                        'id'          => $user->id,
                        'name'        => $user->name,
                        'email'       => $user->email,
                        'roles'       => $roles,
                        'permissions' => $permissions,
                    ],
                ], 200);

            } catch (\Exception $e) {
                return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
            }
        }

        return \response()->json(['message' => 'Credenciales incorrectas o usuario no registrado.'], 401);
    }
}