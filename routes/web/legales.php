<?php

use App\Http\Controllers\Legal\PaginasLegalesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Páginas legales públicas (2026-10-30)
|--------------------------------------------------------------------------
|
| **Sin sesión**, porque Google Play exige que la política de privacidad y la página de eliminación de
| cuenta se puedan abrir desde la ficha de la tienda, sin instalar la app ni tener cuenta. El grupo
| `web` (sesión, CSRF, cookies) lo aplica RouteServiceProvider: hace falta para el formulario de la baja,
| que guarda el acuse en la sesión.
|
| La URL de eliminación de cuenta ya está cargada en Play Console como
| `https://doctorisimo.app/eliminar-cuenta`: **no se cambia sin actualizarla allá**.
|
*/

Route::get('/privacidad', [PaginasLegalesController::class, 'privacidad'])->name('legal.privacidad');
Route::get('/terminos', [PaginasLegalesController::class, 'terminos'])->name('legal.terminos');

Route::prefix('eliminar-cuenta')->name('legal.baja.')->group(function () {
    Route::get('/', [PaginasLegalesController::class, 'eliminarCuenta'])->name('formulario');

    // El pedido se hace con el correo de la cuenta; la baja no arranca hasta que se confirma desde el
    // enlace que llega a ese correo. El throttle frena el uso del formulario como sondeo de correos.
    Route::post('/', [PaginasLegalesController::class, 'solicitar'])->middleware('throttle:6,1')->name('solicitar');

    Route::get('/confirmar/{token}', [PaginasLegalesController::class, 'confirmar'])->name('confirmar');
    Route::post('/cancelar/{token}', [PaginasLegalesController::class, 'cancelar'])->name('cancelar');
});
