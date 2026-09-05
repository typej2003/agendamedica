<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PacienteSyncController;
use App\Http\Controllers\Api\ConsultaSyncController;
use App\Http\Controllers\Api\ColaSyncController;
use App\Http\Controllers\Api\AppAgendaMedicaController;

use App\Http\Controllers\UploadServerController;

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

// ** App para notificacion medica ** //
Route::post('/auth-citamedica', [AppAgendaMedicaController::class, 'authCitaMedica']);



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