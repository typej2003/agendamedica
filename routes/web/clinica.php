<?php

use App\Http\Controllers\Clinica\AgendaController;
use App\Http\Controllers\Clinica\ContextoController;
use App\Http\Controllers\Clinica\NoLaborableController;
use App\Http\Controllers\Clinica\PacienteController;
use App\Http\Controllers\Clinica\SedeController;
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

        // Sedes (WEB-2.8b.1): el lugar donde atiende un médico. Es el **lugar de la clínica**, no de un
        // médico, así que va fuera del grupo que exige contexto: se da de alta antes de asignarle un
        // consultorio a nadie. La segunda mitad —consultorios, modalidad y horarios— va adentro
        // (WEB-2.8b.2/3), porque eso sí es de cada médico.
        Route::get('/sedes', [SedeController::class, 'index'])->name('sedes');
        Route::post('/sedes', [SedeController::class, 'guardar'])->name('sedes.guardar');
        Route::patch('/sedes/{sede}/activar', [SedeController::class, 'activar'])->name('sedes.activar');

        // Todo lo de abajo exige contexto.
        Route::middleware('clinica.contexto')->group(function () {
            Route::get('/', [ShellController::class, 'index'])->name('inicio');

            Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes');
            // El buscador del alta de cita va **antes** de la ruta con parámetro: si no, "buscar"
            // entra por `{paciente}` y no resuelve.
            Route::get('/pacientes/buscar', [PacienteController::class, 'buscar'])->name('pacientes.buscar');
            Route::get('/pacientes/{paciente}', [PacienteController::class, 'ver'])->name('pacientes.ver');

            // Agenda y secretaría (F2): la vista por sede y las acciones del mostrador. La ruta del
            // módulo es `clinica.agenda`, que es la que el menú arma desde `especialidad_modulo`.
            Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::get('/agenda/nueva', [AgendaController::class, 'nueva'])->name('agenda.nueva');
            // Recordatorios y acciones globales del día (WEB-2.6/2.7). Van **antes** de las rutas con
            // `{cola}`: si no, `envio`/`imprimir` entrarían como id de cita.
            Route::get('/agenda/envio', [AgendaController::class, 'envio'])->name('agenda.envio');
            Route::post('/agenda/envio', [AgendaController::class, 'enviarMasivo'])->name('agenda.envio.enviar');
            Route::get('/agenda/imprimir', [AgendaController::class, 'imprimir'])->name('agenda.imprimir');

            // Días no laborables (WEB-2.8): el ABM que el escritorio tiene en `w_horarios` (feriados,
            // congresos, otro consultorio, quirófano). Son parte de la agenda —la secretaria los mueve
            // y la agenda los avisa al agendar—, no del panel de administración.
            Route::get('/agenda/no-laborables', [NoLaborableController::class, 'index'])->name('agenda.no-laborables');
            Route::post('/agenda/no-laborables', [NoLaborableController::class, 'guardar'])->name('agenda.no-laborables.guardar');
            Route::delete('/agenda/no-laborables/{noLaborable}', [NoLaborableController::class, 'eliminar'])->name('agenda.no-laborables.eliminar');

            Route::post('/agenda', [AgendaController::class, 'crear'])->name('agenda.crear');
            Route::post('/agenda/reordenar', [AgendaController::class, 'reordenar'])->name('agenda.reordenar');
            Route::post('/agenda/{cola}/notificar', [AgendaController::class, 'notificar'])->name('agenda.notificar');
            Route::post('/agenda/{cola}/confirmar', [AgendaController::class, 'confirmar'])->name('agenda.confirmar');
            Route::post('/agenda/{cola}/atender', [AgendaController::class, 'atender'])->name('agenda.atender');
            Route::post('/agenda/{cola}/cobrar', [AgendaController::class, 'cobrar'])->name('agenda.cobrar');
            Route::post('/agenda/{cola}/eliminar', [AgendaController::class, 'eliminar'])->name('agenda.eliminar');
            Route::get('/agenda/{cola}/editar', [AgendaController::class, 'editar'])->name('agenda.editar');
            // La última: cualquier POST a `/agenda/<algo>` que no haya matcheado antes cae acá como
            // id de cita, así que las de arriba tienen que estar declaradas primero.
            Route::post('/agenda/{cola}', [AgendaController::class, 'actualizar'])->name('agenda.actualizar');
        });
    });
