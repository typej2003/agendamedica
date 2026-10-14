<?php

use App\Http\Livewire\Admin\ApiKeys;
use App\Http\Livewire\Admin\Comentarios;
use App\Http\Livewire\Admin\Cuentas;
use App\Http\Livewire\Admin\Especialidades;
use App\Http\Livewire\Admin\Servicios;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gestión de usuarios y API keys (Paso 26)
|--------------------------------------------------------------------------
| Archivo propio, aparte de `admin.php`, para no mezclarse con las rutas que ya existían. Acceso: Root o
| Administrador. Las acciones de los componentes Livewire vuelven a comprobarlo en cada petición (ver `hydrate()`).
*/

Route::middleware(['auth', 'role:Root|Administrador'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/cuentas', Cuentas::class)->name('cuentas');
    // Con qué especialidades trabaja cada médico (lo que enciende los módulos de la web clínica).
    Route::get('/especialidades', Especialidades::class)->name('especialidades');
    Route::get('/api-keys', ApiKeys::class)->name('api-keys');
    // Planes de servicio y vencimiento por médico (Paso 27).
    // **Ayúdanos a mejorar** (ROADMAP §9): la bandeja donde recibimos los comentarios del consultorio.
    Route::get('/comentarios', Comentarios::class)->name('comentarios');
    Route::get('/servicios', Servicios::class)->name('servicios');
});

