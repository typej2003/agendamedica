<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PacienteSyncController;
use App\Http\Controllers\Api\ConsultaSyncController;

use App\Models\User;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Consulta;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Rutas con límite de tasa para la recepción de lotes/paquetes desde PowerBuilder
Route::middleware('throttle:1000,1')->group(function () {
    Route::post('/sync/upload-batch', [SyncController::class, 'uploadBatch']);
    Route::post('/pacientes/sincronizar', [PacienteSyncController::class, 'sincronizar']);
    Route::post('/consultas/sincronizar', [ConsultaSyncController::class, 'sincronizar']);
});

// ** App para notificacion medica ** //
Route::post('/auth-citamedica', function (Request $request) {
    // 1. Capturar y limpiar datos
    $email = trim($request->input('email'));
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
        
        // Verificación de roles utilizando Spatie (laravel-permission)
        // Permite acceso si tiene rol de Spatie o si se valida el atributo de rol en caso de usar el modelo User antiguo
        $roles = $user->getRoleNames();
        $hasSpatieRole = $user->hasAnyRole(['aliado', 'aliadoSmartData', 'medico', 'paciente', 'root', 'admin']);
        $hasLegacyRole = isset($user->role) && in_array($user->role, ['aliado', 'aliadoSmartData']);

        if (!$hasSpatieRole && !$hasLegacyRole) {
            return response()->json(['message' => 'No autorizado: El usuario no posee un rol válido.'], 403);
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

            // Obtener roles y permisos formateados con Spatie
            $permissions = $user->getAllPermissions()->pluck('name');

            return response()->json([
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
            return response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
        }
    }

    return response()->json(['message' => 'Credenciales incorrectas'], 401);
});