<?php

use App\Http\Controllers\Paciente\CancelarCitaController;
use App\Http\Controllers\Paciente\MisCitasController;
use App\Http\Controllers\Paciente\ReagendarCitaController;
use App\Http\Controllers\Paciente\RegistroDePacienteController;
use App\Http\Controllers\Paciente\TelefonoDelPacienteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Área del paciente (2026-10-19)
|--------------------------------------------------------------------------
|
| La cuenta del paciente y «Mis citas». El registro es público (sólo invitados) y usa el mismo código de
| 6 dígitos que la reserva. «Mis citas» exige sesión **y el rol Paciente**: la secretaría y el médico no
| entran por acá (su área es `/clinica`). La comprobación de que la cita es del paciente vive en el
| controlador; las rutas con `{cita}` responden 404 si no lo es.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/registro', [RegistroDePacienteController::class, 'formulario'])->name('paciente.registro');
    Route::post('/registro/codigo', [RegistroDePacienteController::class, 'enviarCodigo'])
        ->middleware('throttle:10,1')->name('paciente.registro.codigo.enviar');
    Route::get('/registro/codigo', [RegistroDePacienteController::class, 'codigo'])->name('paciente.registro.codigo');
    Route::post('/registro/confirmar', [RegistroDePacienteController::class, 'confirmar'])
        ->middleware('throttle:20,1')->name('paciente.registro.confirmar');
    Route::post('/registro/reenviar', [RegistroDePacienteController::class, 'reenviar'])
        ->middleware('throttle:10,1')->name('paciente.registro.reenviar');
});

Route::middleware(['auth', 'role:Paciente'])->prefix('mis-citas')->name('paciente.')->group(function () {
    Route::get('/', [MisCitasController::class, 'index'])->name('citas');

    Route::get('/{cita}/cancelar', [CancelarCitaController::class, 'confirmar'])->name('citas.cancelar');
    Route::post('/{cita}/cancelar', [CancelarCitaController::class, 'guardar'])->name('citas.cancelar.guardar');

    // Confirmar o cambiar el teléfono cuando su validación venció (12 meses): mismo código que el registro.
    Route::get('/telefono', [TelefonoDelPacienteController::class, 'formulario'])->name('telefono');
    Route::post('/telefono/codigo', [TelefonoDelPacienteController::class, 'enviarCodigo'])->middleware('throttle:10,1')->name('telefono.codigo');
    Route::post('/telefono/otro', [TelefonoDelPacienteController::class, 'descartar'])->middleware('throttle:20,1')->name('telefono.otro');
    Route::post('/telefono/confirmar', [TelefonoDelPacienteController::class, 'confirmar'])->middleware('throttle:20,1')->name('telefono.confirmar');

    // Reservar/reprogramar exige no estar bloqueado y tener el teléfono validado y vigente.
    Route::middleware('paciente.habilitado')->group(function () {
        Route::get('/{cita}/reprogramar', [ReagendarCitaController::class, 'formulario'])->name('citas.reprogramar');
        Route::post('/{cita}/reprogramar', [ReagendarCitaController::class, 'guardar'])->name('citas.reprogramar.guardar');
    });
});
