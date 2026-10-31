<?php

use App\Http\Controllers\Pagos\PagoPublicoController;
use App\Http\Middleware\PagoPublico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Link público de pago de una cita `/pagar/{token}` (punto 2 del cobro)
|--------------------------------------------------------------------------
|
| El paciente sin cuenta recibe un link por WhatsApp, lo abre y paga con SyPago (débito inmediato + OTP).
| Es un archivo propio, cargado con el grupo `web` por `RouteServiceProvider` (sesión y CSRF), igual que el
| portal de `/reservar`. La privacidad de la página la fija el middleware `PagoPublico`.
|
| El `throttle` lleva su tercer argumento en cada ruta para que no compartan el contador de la misma IP:
| mirar el link es barato, pero iniciar y confirmar hablan con SyPago y piden un límite más estricto.
|
*/

Route::middleware(PagoPublico::class)->prefix('pagar/{token}')->name('pagar.')->group(function () {
    Route::get('/', [PagoPublicoController::class, 'mostrar'])
        ->middleware('throttle:60,1,pagar-mostrar')
        ->name('mostrar');

    Route::post('/iniciar', [PagoPublicoController::class, 'iniciar'])
        ->middleware('throttle:10,1,pagar-iniciar')
        ->name('iniciar');

    Route::post('/confirmar', [PagoPublicoController::class, 'confirmar'])
        ->middleware('throttle:10,1,pagar-confirmar')
        ->name('confirmar');

    Route::post('/consultar', [PagoPublicoController::class, 'consultar'])
        ->middleware('throttle:20,1,pagar-consultar')
        ->name('consultar');
});
