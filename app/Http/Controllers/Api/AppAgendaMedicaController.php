<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Consulta;

class AppAgendaMedicaController extends Controller
{
    /**
     * Autenticación y retorno de agenda médica para la app móvil.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function authCitaMedica(Request $request)
    {
        // 1. Capturar y limpiar datos
        $email = \trim($request->input('email'));
        $password = $request->input('password');

        $user = null;
        $userType = null;

        // 2. Búsqueda progresiva en las 3 tablas (User, Medico, Paciente)
        
        // A. Buscar en Usuarios del Sistema (Root / Admin)
        $user = User::where('email', $email)->first();
        if ($user) {
            $userType = 'root';
        }

        // B. Buscar en Médicos
        if (!$user) {
            $user = Medico::where('email', $email)->first();
            if ($user) {
                $userType = 'medico';
            }
        }

        // C. Buscar en Pacientes
        if (!$user) {
            $user = Paciente::where('email', $email)->first();
            if ($user) {
                $userType = 'paciente';
            }
        }

        // 3. Validación de credenciales
        if ($user && Hash::check($password, $user->password)) {
            
            // Verificación de roles utilizando Spatie (laravel-permission) de manera segura
            $hasSpatieTrait = \method_exists($user, 'getRoleNames');
            
            $roles = $hasSpatieTrait ? $user->getRoleNames() : \collect([$userType]);
            $hasSpatieRole = $hasSpatieTrait ? $user->hasAnyRole(['aliado', 'aliadoSmartData', 'medico', 'paciente', 'root', 'admin']) : false;
            $hasLegacyRole = isset($user->role) && \in_array($user->role, ['aliado', 'aliadoSmartData']);

            // Permitir el acceso si tiene rol de Spatie, rol legacy, o si pertenece directamente a las entidades Medico/Paciente
            if (!$hasSpatieRole && !$hasLegacyRole && !\in_array($userType, ['medico', 'paciente'])) {
                return \response()->json(['message' => 'No autorizado: El usuario no posee un rol válido.'], 403);
            }

            try {
                // Generar Token Sanctum
                $token = $user->createToken('agenda-token')->plainTextToken;

                // Citas del mes actual
                $inicioMes = Carbon::now()->startOfMonth()->toDateString();
                $finMes = Carbon::now()->endOfMonth()->toDateString();

                $citas = Consulta::with(['paciente:id,name,lastname,phonecell'])
                    ->whereBetween('fecha', [$inicioMes, $finMes])
                    ->get();

                // Pacientes registrados para la asignación de citas
                $pacientes = Paciente::select('id', 'name', 'lastname', 'phonecell')
                    ->get();

                // Obtener roles y permisos formateados con Spatie de manera segura
                $permissions = \method_exists($user, 'getAllPermissions') 
                    ? $user->getAllPermissions()->pluck('name') 
                    : \collect([]);

                return \response()->json([
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'user_type'    => $userType,
                    'user'         => [
                        'id'          => $user->id,
                        'name'        => $user->name ?? $user->nombre ?? $user->nombres ?? '',
                        'email'       => $user->email,
                        'roles'       => $roles,
                        'permissions' => $permissions,
                    ],
                    'citas'                   => $citas,
                    'pacientes'               => $pacientes,
                    'capacidad_diaria_maxima' => 8 // Límite de citas diarias para alternar color Verde/Rojo
                ], 200);

            } catch (\Exception $e) {
                return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
            }
        }

        return \response()->json(['message' => 'Credenciales incorrectas'], 401);
    }
}