<?php

use App\Http\Controllers\VerificacionPublicaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Verificación pública del récipe (WEB-5.6)
|--------------------------------------------------------------------------
|
| A donde lleva el QR de la hoja impresa. Lo abre una farmacia con el teléfono: **sin login, sin sesión y sin cookies** (el grupo
| `verificacion`, no `web`), de sólo lectura y con un límite de peticiones por IP. Cada ruta tiene su propio contador (el tercer
| argumento de `throttle`): sin él, todas las rutas con `throttle` comparten el de la misma IP.
|
|  - `/verificar/{token}`: 60 por minuto. El token tiene 256 bits y no se enumera.
|  - `/verificar?codigo=`: 10 por minuto, más estricto: el código corto (8 símbolos) es más corto que el token.
|
*/

Route::get('/verificar', [VerificacionPublicaController::class, 'entrada'])
    ->middleware('throttle:10,1,verificar-codigo')
    ->name('verificacion.entrada');

Route::get('/verificar/{token}', [VerificacionPublicaController::class, 'mostrar'])
    ->middleware('throttle:60,1,verificar-token')
    ->name('verificacion.mostrar');
