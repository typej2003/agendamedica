<?php

use App\Http\Controllers\Clinica\AgendaController;
use App\Http\Controllers\Clinica\CitaController;
use App\Http\Controllers\Clinica\CitasDelPacienteController;
use App\Http\Controllers\Clinica\ConsultorioController;
use App\Http\Controllers\Clinica\ContextoController;
use App\Http\Controllers\Clinica\HorarioController;
use App\Http\Controllers\Clinica\MostradorController;
use App\Http\Controllers\Clinica\MotivoCitaController;
use App\Http\Controllers\Clinica\NoLaborableController;
use App\Http\Controllers\Clinica\PacienteController;
use App\Http\Controllers\Clinica\RecordatorioController;
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

            // Consultorios del médico (WEB-2.8b.2): dónde atiende, con qué modalidad y con qué
            // duración. Es lo que la agenda necesita para calcular la jornada —y lo que un médico
            // nuevo tiene que cargar antes de poder agendar—, así que vive con la agenda y no en el
            // panel. `reg_medico` y `medico_id` salen del contexto: son consultorios **de un médico**.
            Route::get('/consultorios', [ConsultorioController::class, 'index'])->name('consultorios');
            Route::post('/consultorios', [ConsultorioController::class, 'guardar'])->name('consultorios.guardar');
            Route::patch('/consultorios/{consultorio}/activar', [ConsultorioController::class, 'activar'])->name('consultorios.activar');

            // Bloques de horario de cada consultorio (WEB-2.8b.3): qué días, de qué hora a qué hora y
            // con qué cupo. Es lo que la agenda usa para armar la jornada, medir el cupo y decidir la
            // hora de una cita por orden de llegada. Cuelga del consultorio —no es un módulo del menú—
            // porque el mismo médico puede atender distinto en cada lugar donde trabaja.
            Route::get('/consultorios/{consultorio}/horarios', [HorarioController::class, 'index'])->name('consultorios.horarios');
            Route::post('/consultorios/{consultorio}/horarios', [HorarioController::class, 'guardar'])->name('consultorios.horarios.guardar');
            Route::delete('/consultorios/{consultorio}/horarios/{bloque}', [HorarioController::class, 'eliminar'])->name('consultorios.horarios.eliminar');

            Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes');
            // El buscador del alta de cita va **antes** de la ruta con parámetro: si no, "buscar"
            // entra por `{paciente}` y no resuelve.
            Route::get('/pacientes/buscar', [PacienteController::class, 'buscar'])->name('pacientes.buscar');
            // Las citas de un paciente con su modificar/eliminar y el cambio de cédula: el "Cita x
            // Paciente" del escritorio (WEB-2.15). Va **antes** de `/pacientes/{paciente}` por la misma
            // razón que `buscar`: si no, el segmento `citas` entraría como id de paciente.
            Route::get('/pacientes/{paciente}/citas', [CitasDelPacienteController::class, 'index'])->name('pacientes.citas');
            // El cambio de cédula es lo único que no se puede hacer desde otra pantalla (la fuente lo
            // exige con la clave de consulta).
            Route::post('/pacientes/{paciente}/cedula', [CitasDelPacienteController::class, 'cambiarCedula'])->name('pacientes.cedula');
            // Eliminar una cita desde acá: la Action es la misma de la agenda (`EliminarCita`), cambia
            // solo a dónde vuelve. La ruta es propia para no tocar el borrado de la agenda.
            Route::post('/pacientes/{paciente}/citas/{cola}/eliminar', [CitasDelPacienteController::class, 'eliminar'])->name('pacientes.citas.eliminar');
            Route::get('/pacientes/{paciente}', [PacienteController::class, 'ver'])->name('pacientes.ver');

            // Agenda y secretaría (F2): la vista por sede y las acciones del mostrador. La ruta del
            // módulo es `clinica.agenda`, que es la que el menú arma desde `especialidad_modulo`.
            Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
            Route::get('/agenda/nueva', [CitaController::class, 'nueva'])->name('agenda.nueva');
            // Recordatorios y acciones globales del día (WEB-2.6/2.7). Van **antes** de las rutas con
            // `{cola}`: si no, `envio`/`imprimir` entrarían como id de cita.
            Route::get('/agenda/envio', [RecordatorioController::class, 'envio'])->name('agenda.envio');
            Route::post('/agenda/envio', [RecordatorioController::class, 'enviarMasivo'])->name('agenda.envio.enviar');
            Route::get('/agenda/imprimir', [AgendaController::class, 'imprimir'])->name('agenda.imprimir');

            // Días no laborables (WEB-2.8): el ABM que el escritorio tiene en `w_horarios` (feriados,
            // congresos, otro consultorio, quirófano). Son parte de la agenda —la secretaria los mueve
            // y la agenda los avisa al agendar—, no del panel de administración.
            Route::get('/agenda/no-laborables', [NoLaborableController::class, 'index'])->name('agenda.no-laborables');
            Route::post('/agenda/no-laborables', [NoLaborableController::class, 'guardar'])->name('agenda.no-laborables.guardar');
            Route::delete('/agenda/no-laborables/{noLaborable}', [NoLaborableController::class, 'eliminar'])->name('agenda.no-laborables.eliminar');

            // Motivos de cita (WEB-2.17): el catálogo del médico con la marca de "admite varias citas
            // pendientes", que es la excepción a la regla de la cita única. Como los días no laborables,
            // es una herramienta de la agenda y no un módulo del menú. Va **antes** de las rutas con
            // `{cola}` por el mismo motivo que `envio`/`imprimir`.
            Route::get('/agenda/motivos', [MotivoCitaController::class, 'index'])->name('agenda.motivos');
            Route::patch('/agenda/motivos/{motivo}/alternar', [MotivoCitaController::class, 'alternar'])->name('agenda.motivos.alternar');

            Route::post('/agenda', [CitaController::class, 'crear'])->name('agenda.crear');
            Route::post('/agenda/reordenar', [MostradorController::class, 'reordenar'])->name('agenda.reordenar');
            Route::post('/agenda/{cola}/notificar', [RecordatorioController::class, 'notificar'])->name('agenda.notificar');
            Route::post('/agenda/{cola}/confirmar', [MostradorController::class, 'confirmar'])->name('agenda.confirmar');
            Route::post('/agenda/{cola}/atender', [MostradorController::class, 'atender'])->name('agenda.atender');
            Route::post('/agenda/{cola}/cobrar', [MostradorController::class, 'cobrar'])->name('agenda.cobrar');
            Route::post('/agenda/{cola}/eliminar', [CitaController::class, 'eliminar'])->name('agenda.eliminar');
            Route::get('/agenda/{cola}/editar', [CitaController::class, 'editar'])->name('agenda.editar');
            // La última: cualquier POST a `/agenda/<algo>` que no haya matcheado antes cae acá como
            // id de cita, así que las de arriba tienen que estar declaradas primero.
            Route::post('/agenda/{cola}', [CitaController::class, 'actualizar'])->name('agenda.actualizar');
        });
    });
