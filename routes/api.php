<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PacienteSyncController;

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

// Ruta para la recepción de lotes/paquetes desde PowerBuilder
// En routes/api.php
Route::post('/sync/upload-batch', [SyncController::class, 'uploadBatch'])
    ->middleware('throttle:1000,1'); // Permite hasta 1000 peticiones por minuto

    Route::post('/pacientes/sincronizar', [PacienteSyncController::class, 'sincronizar']);