<?php

use App\Http\Controllers\RegistroDeMedicos\RegistroDeMedicoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registro de médicos con aprobación (ROADMAP §10.4, fase 2)
|--------------------------------------------------------------------------
|
| Público, sólo invitados: el médico llena la solicitud, confirma su correo con un código de 6 dígitos y la
| solicitud queda **pendiente**. No crea cuenta: la crea el administrador al aprobarla en
| `/admin/solicitudes-medicos` (ver `routes/web/cuentas.php`).
|
*/

Route::middleware('guest')->prefix('registro/medico')->name('medico.registro')->group(function () {
    Route::get('/', [RegistroDeMedicoController::class, 'formulario']);
    Route::post('/codigo', [RegistroDeMedicoController::class, 'enviarCodigo'])
        ->middleware('throttle:10,1')->name('.codigo.enviar');
    Route::get('/codigo', [RegistroDeMedicoController::class, 'codigo'])->name('.codigo');
    Route::post('/confirmar', [RegistroDeMedicoController::class, 'confirmar'])
        ->middleware('throttle:20,1')->name('.confirmar');
    Route::post('/reenviar', [RegistroDeMedicoController::class, 'reenviar'])
        ->middleware('throttle:10,1')->name('.reenviar');
    Route::get('/recibida', [RegistroDeMedicoController::class, 'recibida'])->name('.recibida');
});
