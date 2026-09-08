<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PacienteSyncController;
use App\Http\Controllers\Api\ConsultaSyncController;
use App\Http\Controllers\Api\ColaSyncController;
use App\Http\Controllers\Api\LoginAppController;
use App\Http\Controllers\Api\RefreshAppController;
use App\Http\Controllers\Api\UploadServerController;

use App\Http\Controllers\WhatsAppWebhookController;
use App\Services\WhatsAppService;
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
    Route::post('/cola/sincronizar', [ColaSyncController::class, 'sincronizar']);
});

// ** App para notificación médica ** //
Route::post('/app/login', [LoginAppController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/app/refresh-data', [RefreshAppController::class, 'refreshData']);
});

Route::prefix('upload-servers')->group(function () {
    // Listar todos los registros de subida
    Route::get('/', [UploadServerController::class, 'index']);

    // Crear un nuevo registro de subida
    Route::post('/', [UploadServerController::class, 'store']);

    // Obtener la última subida exitosa de una entidad (ej: /api/upload-servers/last/pacientes?batch_type=nuevos)
    Route::get('/last/{entityType}', [UploadServerController::class, 'getLastUpload']);

    // Consultar el detalle de una subida por ID
    Route::get('/{id}', [UploadServerController::class, 'show']);
});

// Rutas de Webhook para Meta
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

// Ruta endpoint para enviar recordatorio a un paciente
Route::post('/whatsapp/send-reminder', function (Request $request, WhatsAppService $whatsAppService) {
    $request->validate([
        'phone' => 'required|string',
        'patient_name' => 'required|string',
    ]);

    // Enviar plantilla "notificacion_paciente" con el nombre como parámetro {{1}}
    $result = $whatsAppService->sendTemplate(
        $request->input('phone'),
        'notificacion_paciente',
        [$request->input('patient_name')]
    );

    return response()->json($result);
});