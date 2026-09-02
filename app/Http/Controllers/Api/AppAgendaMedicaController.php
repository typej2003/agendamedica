<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Consulta;

class AppAgendaMedicaController extends Controller
{
    /**
     * Autenticación y retorno de agenda médica para la app móvil.
     * Adaptado para funcionar sin modificar las estructuras o traits de los modelos.
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
            
            // Verificación de roles utilizando Spatie de manera segura
            $hasSpatieTrait = \method_exists($user, 'getRoleNames');
            
            $roles = $hasSpatieTrait ? $user->getRoleNames() : \collect([$userType]);
            
            $hasSpatieRole = false;
            if ($hasSpatieTrait) {
                try {
                    $hasSpatieRole = $user->hasAnyRole(['medico', 'paciente', 'root', 'admin']);
                } catch (\Throwable $e) {
                    $hasSpatieRole = false;
                }
            }

            // Permitir el acceso si el usuario es directamente Medico/Paciente o si tiene un rol asignado en Spatie
            $isDirectEntity = \in_array($userType, ['medico', 'paciente']);

            if (!$isDirectEntity && !$hasSpatieRole) {
                return \response()->json(['message' => 'No autorizado: El usuario no posee un rol válido.'], 403);
            }

            try {
                // Generar Token Sanctum si existe el método; si no, generar un token alternativo seguro en string
                $token = \method_exists($user, 'createToken')
                    ? $user->createToken('agenda-token')->plainTextToken
                    : \base64_encode(Str::random(40) . '|' . $user->id);

                // Citas del mes actual
                $inicioMes = Carbon::now()->startOfMonth()->toDateString();
                $finMes = Carbon::now()->endOfMonth()->toDateString();

                // Obtener consultas del mes
                $consultas = Consulta::whereBetween('fecha', [$inicioMes, $finMes])->get();

                // Obtener pacientes para cruce manual sin requerir la relación Eloquent
                $pacientesRaw = Paciente::all();
                
                // Mapear pacientes para ajustar columnas nombres/apellidos/telefono a name/lastname/phonecell
                $pacientes = $pacientesRaw->map(function ($p) {
                    return [
                        'id'        => $p->id,
                        'name'      => $p->nombres ?? $p->name ?? '',
                        'lastname'  => $p->apellidos ?? $p->lastname ?? '',
                        'phonecell' => $p->telefono ?? $p->phonecell ?? '',
                    ];
                });

                // Asignar el paciente mapeado a cada cita manualmente
                $citas = $consultas->map(function ($consulta) use ($pacientes) {
                    // Buscar coincidencia por numhistoria o por paciente_id
                    $pacienteId = $consulta->paciente_id ?? $consulta->numhistoria;
                    $pacienteEncontrado = $pacientes->firstWhere('id', $pacienteId);

                    $consultaArray = $consulta->toArray();
                    $consultaArray['paciente'] = $pacienteEncontrado ?? null;

                    return $consultaArray;
                });

                // Obtener permisos formateados con Spatie de manera segura
                $permissions = \method_exists($user, 'getAllPermissions') 
                    ? $user->getAllPermissions()->pluck('name') 
                    : \collect([]);

                // Determinar el nombre a mostrar según los campos presentes en el objeto
                $nombreUsuario = $user->name 
                    ?? ($user->nombres ? \trim($user->nombres . ' ' . ($user->apellidos ?? '')) : null)
                    ?? $user->nombre 
                    ?? '';

                return \response()->json([
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'user_type'    => $userType,
                    'user'         => [
                        'id'          => $user->id,
                        'name'        => $nombreUsuario,
                        'email'       => $user->email,
                        'roles'       => $roles,
                        'permissions' => $permissions,
                    ],
                    'citas'                   => $citas,
                    'pacientes'               => $pacientes->values(),
                    'capacidad_diaria_maxima' => 8 // Límite de citas diarias para alternar color Verde/Rojo
                ], 200);

            } catch (\Exception $e) {
                return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
            }
        }

        return \response()->json(['message' => 'Credenciales incorrectas'], 401);
    }
}