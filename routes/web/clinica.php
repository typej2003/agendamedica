<?php

use App\Http\Controllers\Clinica\AgendaController;
use App\Http\Controllers\Clinica\ContextoController;
use App\Http\Controllers\Clinica\PacienteController;
use App\Http\Controllers\Clinica\ShellController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web clínica (PLAN-WEB.md)
|--------------------------------------------------------------------------
|
| Área aparte del panel de administración: misma sesión y mismos roles, otro layout y otra navegación.
| El grupo `web` (con EnsureAccountActive y EnsurePasswordChanged) lo aplica RouteServiceProvider, así
| que una cuenta bloqueada o con clave temporal ya queda cubierta acá igual que en el panel.
|
| El menú NO se escribe en las rutas: sale de los módulos implementados de la especialidad del contexto
| (`especialidad_modulo`), para que agregar una especialidad no obligue a tocar este archivo.
|
*/

Route::middleware(['auth', 'role:Root|Medico|Secretaria'])
    ->prefix('clinica')
    ->name('clinica.')
    ->group(function () {
        // Elegir / cambiar el contexto de trabajo (médico, especialidad, sede).
        Route::get('/contexto', [ContextoController::class, 'editar'])->name('contexto');
        Route::post('/contexto', [ContextoController::class, 'guardar'])->name('contexto.guardar');
        Route::delete('/contexto', [ContextoController::class, 'salir'])->name('contexto.salir');

        // Todo lo de abajo exige contexto.
        Route::middleware('clinica.contexto')->group(function () {
            Route::get('/', [ShellController::class, 'index'])->name('inicio');

            Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes');
            Route::get('/pacientes/{paciente}', [PacienteController::class, 'ver'])->name('pacientes.ver');

            // Agenda y secretaría (F2): la vista por sede y las acciones del mostrador. La ruta del
            // módulo es `clinica.agenda`, que es la que el menú arma desde `especialidad_modulo`.
            Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::post('/agenda/reordenar', [AgendaController::class, 'reordenar'])->name('agenda.reordenar');
            Route::post('/agenda/{cola}/confirmar', [AgendaController::class, 'confirmar'])->name('agenda.confirmar');
            Route::post('/agenda/{cola}/atender', [AgendaController::class, 'atender'])->name('agenda.atender');
            Route::post('/agenda/{cola}/cobrar', [AgendaController::class, 'cobrar'])->name('agenda.cobrar');
        });
    });
