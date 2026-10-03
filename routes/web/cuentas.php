<?php

use App\Http\Livewire\Admin\Cuentas;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gestión de usuarios (Paso 26)
|--------------------------------------------------------------------------
| Archivo propio, aparte de `admin.php`, para no mezclarse con las rutas que ya existían. Acceso: Root o
| Administrador. Las acciones del componente Livewire vuelven a comprobarlo en cada petición (ver Cuentas::hydrate).
*/

Route::middleware(['auth', 'role:Root|Administrador'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/cuentas', Cuentas::class)->name('cuentas');
});
