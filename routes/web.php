<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Livewire\Components\ListSearch;
use App\Http\Livewire\Components\ViewCalendar;
use App\Http\Livewire\Components\DaySchedule;
use App\Http\Controllers\Auth\CustomLoginController;
use App\Http\Controllers\Auth\CambiarPasswordWebController;
use App\Http\Controllers\Auth\ElegirPanelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PortadaController;
use App\Http\Controllers\SeoPublicoController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// La portada le habla al médico (el registro es la conversión) y deja un bloque al paciente: ver `PortadaController`.
Route::get('/', PortadaController::class)->name('welcome');

// 1. Deshabilitamos las rutas de login por defecto de Auth::routes()
//    El alta de cuentas también: el registro de médicos (con aprobación) y el de pacientes son las
//    fases siguientes del login; el formulario de `/register` creaba una cuenta sin rol y no se usa.
Auth::routes(['login' => false, 'register' => false]);

// 2. Definimos las rutas de Login personalizada (GET para mostrar el form y POST para autenticar).
//    Es **un solo login**: el tipo de cuenta lo resuelve `AutenticacionPorCorreo`, no la pantalla.
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', [CustomLoginController::class, 'login']);

// Redirige la antigua ruta /home a la nueva /dashboard
Route::redirect('/home', '/dashboard');

Route::middleware(['auth'])->group(function () {
    // Usamos un controlador para la vista del dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // «¿Dónde quieres entrar?»: sólo la ven las cuentas con más de un área (ver `User::rutaDeEntrada`).
    Route::get('/elegir-panel', [ElegirPanelController::class, 'index'])->name('elegir-panel');

    // Cambio de contraseña (obligatorio si la clave es temporal — ver EnsurePasswordChanged).
    Route::get('/cambiar-password', [CambiarPasswordWebController::class, 'formulario'])->name('cuenta.password');
    Route::post('/cambiar-password', [CambiarPasswordWebController::class, 'actualizar'])->name('cuenta.password.update');

    // Ruta para la gestión de usuarios (protegida por permiso)
    Route::get('/users', \App\Http\Livewire\Admin\ListUsers::class)->name('users.index')->middleware('role:Root|Administrador');

    // Ruta para asignar permisos a usuarios
    Route::get('/users/permissions', \App\Http\Livewire\Admin\PermissionsUsers::class)->name('users.permissions')->middleware('role:Root|Administrador');
});

// Búsqueda heredada: ningún menú ni correo la enlaza (el registro y las reservas tienen sus propias pantallas), así que no se
// indexa. La cabecera sirve aunque la página no tenga título ni descripción propios.
Route::group(['middleware' => [function ($request, $next) {
    return tap($next($request), fn ($respuesta) => $respuesta->headers->set('X-Robots-Tag', 'noindex, follow'));
}]], function () {
    Route::get('/medicos/buscar', ListSearch::class)->name('medicos.search');

    Route::get('/register/patient', ListSearch::class)->name('register.patient');
    Route::get('/register/doctor', ListSearch::class)->name('register.doctor');
});

// Para los buscadores: el mapa del sitio y las reglas de rastreo (ver `SeoPublicoController`).
Route::get('/sitemap.xml', [SeoPublicoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SeoPublicoController::class, 'robots'])->name('seo.robots');

Route::middleware(['auth'])->group(function () {
    Route::get('/agendar/{medicoId}/{medicalCenterId?}', ViewCalendar::class)->name('agendar.cita');
    Route::get('/agendar/{medicoId}/dia/{fecha}', DaySchedule::class)->name('agendar.dia');
});
