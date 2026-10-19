<?php

use App\Http\Controllers\Reservas\CatalogoDeReservasController;
use App\Http\Controllers\Reservas\ReservaPublicaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal público de reservas (2026-10-19)
|--------------------------------------------------------------------------
|
| La cara del paciente: **sin sesión ni cuenta**. Catálogo de médicos con agenda publicada, elección de
| un horario y verificación del teléfono con un código de 6 dígitos antes de crear la cita. Es un
| archivo propio para no mezclarlo con el panel ni con la web clínica; el grupo `web` lo aplica
| RouteServiceProvider, así que tiene CSRF, sesión y los middlewares de cuentas (que a un invitado no le
| hacen nada).
|
| El `whereNumber` de `{medico}` es necesario: `/reservar/confirmada` no es el id de un médico.
|
*/

Route::prefix('reservar')->name('reservas.')->group(function () {
    Route::get('/', [CatalogoDeReservasController::class, 'index'])->name('catalogo');

    // El comprobante vive en la sesión; va antes de `/{medico}` por claridad, aunque el `whereNumber` ya
    // impide que "confirmada" se tome como un id.
    Route::get('/confirmada', [ReservaPublicaController::class, 'confirmada'])->name('confirmada');

    Route::get('/{medico}', [CatalogoDeReservasController::class, 'medico'])
        ->whereNumber('medico')->name('medico');

    Route::post('/{medico}/codigo', [ReservaPublicaController::class, 'enviarCodigo'])
        ->whereNumber('medico')->middleware('throttle:10,1')->name('codigo.enviar');

    Route::get('/{medico}/codigo', [ReservaPublicaController::class, 'codigo'])
        ->whereNumber('medico')->name('codigo');

    Route::post('/{medico}/confirmar', [ReservaPublicaController::class, 'confirmar'])
        ->whereNumber('medico')->middleware('throttle:20,1')->name('confirmar');
});
