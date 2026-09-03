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
use App\Models\MotivoCita;
use App\Models\MedicoPaciente;

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
        $medicoModel = null;

        // 2. Intentar autenticar contra la tabla `users` (Caso Root / Admin)
        $rootUser = User::where('email', $email)->first();
        if ($rootUser && Hash::check($password, $rootUser->password)) {
            $user = $rootUser;
            $userType = 'Root';
            
            // Si el usuario Root también tiene un registro en la tabla `medicos`
            $medicoModel = Medico::where('user_id', $user->id)->orWhere('email', $email)->first();
        }

        // 3. Caso Médico
        if (!$user) {
            $medico = Medico::where('email', $email)->first();
            if ($medico && Hash::check($password, $medico->password)) {
                $userType = 'Medico';
                $medicoModel = $medico;

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

        // 5. Respuesta JSON si se validó y creó/obtuvo el $user
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

                // Fechas para la consulta del mes actual
                $inicioMes = Carbon::now()->startOfMonth()->toDateString();
                $finMes = Carbon::now()->endOfMonth()->toDateString();

                // 6. Obtener Pacientes y Consultas según el tipo de usuario
                if ($userType === 'Medico' || ($userType === 'Root' && $medicoModel)) {
                    // Buscar las relaciones pivote asociadas al medico_id
                    $relaciones = MedicoPaciente::where('medico_id', $medicoModel->id)->get();
                    
                    $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
                    $historias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();
                    $historiasMap = $relaciones->pluck('numhistoria', 'paciente_id')->toArray();

                    // Consultar los pacientes usando los IDs obtenidos
                    $pacientesRaw = Paciente::whereIn('id', $pacienteIds)->get();

                    // Mapear el número de historia proveniente de la tabla pivote
                    $pacientesRaw->each(function ($p) use ($historiasMap) {
                        $p->numhistoria_pivote = $historiasMap[$p->id] ?? $p->numhistoria ?? '';
                    });

                    // Consultar la tabla `consultas` filtrando por numhistoria
                    $consultas = Consulta::whereIn('numhistoria', $historias)
                        ->whereBetween('fecha', [$inicioMes, $finMes])
                        ->get();

                } elseif ($userType === 'Paciente') {
                    $pacienteModel = Paciente::where('user_id', $user->id)->orWhere('email', $email)->first();
                    $pacientesRaw = $pacienteModel ? \collect([$pacienteModel]) : \collect([]);

                    $numHistoriaPac = $pacienteModel ? ($pacienteModel->numhistoria ?? '') : '';

                    $consultas = !empty($numHistoriaPac)
                        ? Consulta::where('numhistoria', $numHistoriaPac)->whereBetween('fecha', [$inicioMes, $finMes])->get() 
                        : \collect([]);

                } else {
                    // Caso Root sin modelo médico específico
                    $pacientesRaw = Paciente::all();
                    $consultas = Consulta::whereBetween('fecha', [$inicioMes, $finMes])->get();
                }

                // Mapear los pacientes para la respuesta JSON
                $pacientes = $pacientesRaw->map(function ($p) {
                    return [
                        'id'          => $p->id,
                        'name'        => $p->nombres ?? $p->name ?? '',
                        'lastname'    => $p->apellidos ?? $p->lastname ?? '',
                        'phonecell'   => $p->telefono ?? $p->phonecell ?? '',
                        'numhistoria' => $p->numhistoria_pivote ?? $p->numhistoria ?? '',
                    ];
                });

                // Mapear las citas/consultas asociando el paciente mediante numhistoria
                $citas = $consultas->map(function ($consulta) use ($pacientes) {
                    $numHistoriaConsulta = $consulta->numhistoria ?? null;
                    $pacienteEncontrado = $pacientes->firstWhere('numhistoria', $numHistoriaConsulta);

                    $consultaArray = $consulta->toArray();
                    $consultaArray['paciente'] = $pacienteEncontrado ?? null;

                    return $consultaArray;
                });

                // Obtención de la tabla de motivos de cita
                $motivos = MotivoCita::all();

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
                    'citas'                   => $citas,
                    'pacientes'               => $pacientes->values(),
                    'motivos'                 => $motivos,
                    'capacidad_diaria_maxima' => 8
                ], 200);

            } catch (\Exception $e) {
                return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
            }
        }

        return \response()->json(['message' => 'Credenciales incorrectas o usuario no registrado.'], 401);
    }
}