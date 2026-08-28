<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Livewire\Welcome;
use App\Http\Livewire\Components\ListSearch;
use App\Http\Livewire\Components\ViewCalendar;
use App\Http\Controllers\Auth\CustomLoginController;
use App\Http\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', Welcome::class)->name('welcome');

// 1. Deshabilitamos las rutas de login por defecto de Auth::routes()
Auth::routes(['login' => false]);

// 2. Definimos las rutas de Login personalizada (GET para mostrar el form y POST para autenticar)
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', [CustomLoginController::class, 'login']);

// Redirige la antigua ruta /home a la nueva /dashboard
Route::redirect('/home', '/dashboard');

Route::middleware(['auth'])->group(function () {
    // Usamos un controlador para la vista del dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Ruta para la gestión de usuarios (protegida por permiso)
    Route::get('/users', \App\Http\Livewire\Admin\ListUsers::class)->name('users.index')->middleware('role:Root|Administrador');

    // Ruta para asignar permisos a usuarios
    Route::get('/users/permissions', \App\Http\Livewire\Admin\PermissionsUsers::class)->name('users.permissions')->middleware('role:Root|Administrador');
});

Route::get('/medicos/buscar', ListSearch::class)->name('medicos.search');

Route::get('/register/patient', ListSearch::class)->name('register.patient');
Route::get('/register/doctor', ListSearch::class)->name('register.doctor');

Route::middleware(['auth'])->group(function () {
    Route::get('/agendar/{medicoId}/{medicalCenterId?}', ViewCalendar::class)->name('agendar.cita');
});