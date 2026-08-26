<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Livewire\Welcome;
use App\Http\Livewire\Components\ListSearch;
use App\Http\Controllers\Auth\CustomLoginController;
use App\Http\Controllers\DashboardController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', Welcome::class)->name('welcome');

// Rutas de autenticación predeterminadas de Laravel
Auth::routes();

// Sobrescribimos el POST de Login para utilizar el CustomLoginController (Médico / Paciente)
Route::post('/login', [CustomLoginController::class, 'login'])->name('login');

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