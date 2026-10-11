<?php

use App\Http\Controllers\Clinica\AgendaController;
use App\Http\Controllers\Clinica\CitaController;
use App\Http\Controllers\Clinica\CitasDelPacienteController;
use App\Http\Controllers\Clinica\ConsultaAntecedentesController;
use App\Http\Controllers\Clinica\ConsultaCerrarController;
use App\Http\Controllers\Clinica\ConsultaController;
use App\Http\Controllers\Clinica\ConsultaDiagnosticosController;
use App\Http\Controllers\Clinica\ConsultaEnfermedadController;
use App\Http\Controllers\Clinica\ConsultaEvaluacionController;
use App\Http\Controllers\Clinica\ConsultaEvolucionController;
use App\Http\Controllers\Clinica\ConsultaImagenesController;
use App\Http\Controllers\Clinica\ConsultaMotivosController;
use App\Http\Controllers\Clinica\ConsultaRecipeParejaController;
use App\Http\Controllers\Clinica\ConsultaRecipesController;
use App\Http\Controllers\Clinica\ConsultorioController;
use App\Http\Controllers\Clinica\ContextoController;
use App\Http\Controllers\Clinica\CumpleanosController;
use App\Http\Controllers\Clinica\HistoriaDelPacienteController;
use App\Http\Controllers\Clinica\HorarioController;
use App\Http\Controllers\Clinica\MostradorController;
use App\Http\Controllers\Clinica\MotivoCitaController;
use App\Http\Controllers\Clinica\NoLaborableController;
use App\Http\Controllers\Clinica\PacienteController;
use App\Http\Controllers\Clinica\RecordatorioController;
use App\Http\Controllers\Clinica\RepresentanteController;
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
            // Alta y edición de la ficha (WEB-3.2). Mismas dos razones que `buscar` para ir **antes** de
            // `/pacientes/{paciente}`: `nuevo` y `{paciente}/editar` no son ids de paciente.
            Route::get('/pacientes/nuevo', [PacienteController::class, 'nuevo'])->name('pacientes.nuevo');
            Route::post('/pacientes', [PacienteController::class, 'guardar'])->name('pacientes.guardar');
            Route::get('/pacientes/{paciente}/editar', [PacienteController::class, 'editar'])->name('pacientes.editar');
            // Asignarle el número de historia al paciente que todavía no lo tiene (WEB-3.3): la Action
            // compartida con "Pasar a cita formal" (WEB-2.11) y con el sync del móvil. La hace la
            // secretaría al recibir al paciente, o el médico si llega a atenderlo sin historia; el
            // `reg_medico` sale del vínculo del paciente y **no** del formulario.
            Route::post('/pacientes/{paciente}/historia', [PacienteController::class, 'asignarHistoria'])->name('pacientes.asignar-historia');
            // La **historia del paciente** (WEB-3.5): sus consultas, sus antecedentes y sus documentos, y
            // la impresión del resumen. Es de **sólo lectura** (la consulta es F4 y los documentos, F5) y
            // va aparte de la ficha porque es otra responsabilidad: la ficha es la filiación y el contacto,
            // la historia es lo que pasó en las consultas. Un paciente de otro médico da 404.
            Route::get('/pacientes/{paciente}/historia', [HistoriaDelPacienteController::class, 'ver'])->name('pacientes.historia');
            Route::get('/pacientes/{paciente}/historia/imprimir', [HistoriaDelPacienteController::class, 'imprimir'])->name('pacientes.historia.imprimir');
            // El representante del paciente (WEB-3.4): el adulto que responde por él y el que presta el
            // teléfono para el recordatorio. Es un **upsert por historia** (la tabla legada tiene una fila
            // por `numhistoria`), así que cargar y editar son el mismo POST; el formulario vive en el
            // bloque de la ficha, que es la única pantalla que lo muestra.
            Route::post('/pacientes/{paciente}/representante', [RepresentanteController::class, 'guardar'])->name('pacientes.representante');
            Route::put('/pacientes/{paciente}', [PacienteController::class, 'actualizar'])->name('pacientes.actualizar');
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

            // **La consulta** (F4/WEB-4.1): la pantalla de la atención, con el panel de 13 ítems del
            // legado. Es **sólo del médico**: la secretaría no entra a la ficha clínica (PLAN-WEB.md
            // §3.14, Nivel 3 del legado), así que vive en un grupo con sus propios roles y no en el que
            // comparte con la agenda. El grupo `clinica.contexto` sigue valiendo: una consulta se lee con
            // el médico del contexto.
            //
            // No cuelga de `/pacientes/{paciente}` porque la consulta no es del paciente: es **de una
            // historia** (`numhistoria` + `nroconsulta`), y ese es el vínculo que el escritorio y el sync
            // comparten. El controlador resuelve el paciente para el encabezado y responde 404 si la
            // consulta es de otro consultorio.
            Route::middleware('role:Root|Medico')->group(function () {
                Route::get('/consulta/{consulta}', [ConsultaController::class, 'ver'])->name('consulta');

                // El contenido de las dos primeras secciones (WEB-4.2), que se abre **dentro** del shell
                // con `?seccion=`. Son acciones —escriben— y por eso viven en controladores propios
                // (la guía de la web parte los controladores por responsabilidad, no por pantalla).
                //
                // Motivos de consulta: agregar uno del catálogo, crear el que falta (online-only,
                // PLAN-WEB.md §3.15) y quitarlo. `buscar` es JSON y va **antes** de las rutas con
                // parámetro para que `buscar` no entre como id de un motivo.
                Route::get('/consulta/{consulta}/motivos/buscar', [ConsultaMotivosController::class, 'buscar'])->name('consulta.motivos.buscar');
                Route::post('/consulta/{consulta}/motivos', [ConsultaMotivosController::class, 'agregar'])->name('consulta.motivos.agregar');
                Route::post('/consulta/{consulta}/motivos/crear', [ConsultaMotivosController::class, 'crear'])->name('consulta.motivos.crear');
                Route::delete('/consulta/{consulta}/motivos/{motivo}', [ConsultaMotivosController::class, 'quitar'])->name('consulta.motivos.quitar');

                // Enfermedad actual: se guarda con un botón, no hay autoguardado.
                Route::post('/consulta/{consulta}/enfermedad', [ConsultaEnfermedadController::class, 'guardar'])->name('consulta.enfermedad.guardar');

                // Evaluación física (WEB-4.3): el examen general y el ginecológico. Son **dos tablas**
                // (`consultas` y `examen_fisico_nuevo`) y se guardan juntas, con el mismo botón explícito
                // de la enfermedad actual. La colposcopia y la citología —que viven en esa misma tabla—
                // son de F6 y no entran acá.
                Route::post('/consulta/{consulta}/evaluacion', [ConsultaEvaluacionController::class, 'guardar'])->name('consulta.evaluacion.guardar');

                // Antecedentes del paciente (WEB-4.4): el ítem 3 del panel. **No son de la consulta** —
                // `antece_paciente` se llavea por `numhistoria` y el escritorio los carga y los lee por
                // historia (`w_consulta_principal.srw:7416`, `:7636`, `:7754`)—, así que la Action vive en
                // `Actions\Pacientes`; la sección se abre dentro de la consulta porque es el ítem 3 de su
                // panel. `buscar` es el buscador del catálogo (JSON) y va **antes** de las rutas con
                // parámetro.
                Route::get('/consulta/{consulta}/antecedentes/buscar', [ConsultaAntecedentesController::class, 'buscar'])->name('consulta.antecedentes.buscar');
                Route::post('/consulta/{consulta}/antecedentes', [ConsultaAntecedentesController::class, 'guardar'])->name('consulta.antecedentes.guardar');
                Route::post('/consulta/{consulta}/antecedentes/crear', [ConsultaAntecedentesController::class, 'crear'])->name('consulta.antecedentes.crear');

                // Diagnósticos de la consulta (WEB-4.5): el ítem 8 del panel. La fila de
                // `diagnostico_paciente` **sí es de la consulta** —se llavea por `nrohistoria` +
                // `nroconsulta` + `codediagnostico` (`d_diagnostico_consulta_1.srd`, `retrieve(historia,
                // consulta)` en `w_consulta_principal.srw:3007`)—, así que la Action vive en
                // `Actions\Consulta`. La sección es de **guardado explícito**: un solo POST aplica el
                // detalle editado, lo tildado del catálogo y lo quitado (el `dw_8.update()` del escritorio
                // también espera al *Guardar* de la ventana, `:2709`).
                Route::post('/consulta/{consulta}/diagnosticos', [ConsultaDiagnosticosController::class, 'guardar'])->name('consulta.diagnosticos.guardar');
                Route::post('/consulta/{consulta}/diagnosticos/crear', [ConsultaDiagnosticosController::class, 'crear'])->name('consulta.diagnosticos.crear');

                // Evolución de la consulta (WEB-4.6): el ítem 9 del panel. **Es una columna de la
                // consulta** —`consultas.evolucion`, que el `INSERT`/`UPDATE` de `w_consulta_principal`
                // escribe desde el campo de su pestaña *Evolución* (`:2738`, `:2835`) y que la ventana
                // relee con `SELECT evolucion … WHERE numhistoria = :historia AND nroconsulta = :consulta`
                // (`:4218-4242`)—, así que la Action vive en `Actions\Consulta`. Como la enfermedad actual,
                // se guarda con un botón explícito y no con autoguardado. La sección no toca
                // `consultas.observaciones`: en la fuente esa columna es la **colposcopia** del examen
                // ginecológico (`:2757`, `:2836`), no un campo de la evolución (ver `EvolucionDeLaConsulta`).
                Route::post('/consulta/{consulta}/evolucion', [ConsultaEvolucionController::class, 'guardar'])->name('consulta.evolucion.guardar');

                // Exámenes e imágenes de la consulta (WEB-4.7): el ítem 7 del panel. La sección **lee**
                // los exámenes de laboratorio de la consulta (`examen_paciente`, que escribe el
                // escritorio: su ABM es F5/WEB-5.4) y **escribe** las imágenes (`imagen_consulta`, la
                // misma tabla que el escritorio llena con la ruta del archivo, `w_consulta_principal.srw:10744`).
                //
                // La subida y la baja son POST/DELETE normales —el archivo viaja en un `<form>` con
                // `multipart/form-data`— y la descarga es un GET **autorizado**: el disco es privado
                // (`storage/app/clinica`, fuera de `public/`), así que esta ruta es el único camino al
                // archivo y comprueba el `reg_medico` del contexto contra el dueño de la fila. Una imagen
                // de otro médico —o de otra consulta— responde 404.
                Route::post('/consulta/{consulta}/examenes', [ConsultaImagenesController::class, 'subir'])->name('consulta.examenes.subir');
                Route::get('/consulta/{consulta}/examenes/{imagen}', [ConsultaImagenesController::class, 'descargar'])->name('consulta.examenes.descargar');
                Route::delete('/consulta/{consulta}/examenes/{imagen}', [ConsultaImagenesController::class, 'quitar'])->name('consulta.examenes.quitar');

                // Récipes de la consulta (WEB-5.1): el ítem 10 del panel. Cada medicamento es una fila de
                // `recipes` y se agrupan por `nrohistoria` + `nroconsulta` + `recipe` (el `NULL` del
                // escritorio es el récipe 1), así que la consulta tiene **varios** y cualquiera se corrige
                // o se elimina en el lugar. La regla de escritura es una Action de `Actions\Consulta`
                // (`GuardarRecipe`, la misma que usa el sync del móvil). El vademécum y los tratamientos se
                // buscan por JSON del médico del contexto; crear el medicamento que falta es online-only.
                // `medicamentos` va **antes** de `{numero}` y el número se acota a dígitos: un récipe de otro
                // médico —o de otra consulta— responde 404.
                Route::get('/consulta/{consulta}/recipes/medicamentos', [ConsultaRecipesController::class, 'medicamentos'])->name('consulta.recipes.medicamentos');
                Route::get('/consulta/{consulta}/recipes/tratamientos', [ConsultaRecipesController::class, 'tratamientos'])->name('consulta.recipes.tratamientos');
                Route::post('/consulta/{consulta}/recipes/medicamentos', [ConsultaRecipesController::class, 'crearMedicamento'])->name('consulta.recipes.medicamentos.crear');
                Route::post('/consulta/{consulta}/recipes', [ConsultaRecipesController::class, 'crear'])->name('consulta.recipes.crear');
                Route::post('/consulta/{consulta}/recipes/{numero}', [ConsultaRecipesController::class, 'guardar'])->whereNumber('numero')->name('consulta.recipes.guardar');
                Route::delete('/consulta/{consulta}/recipes/{numero}', [ConsultaRecipesController::class, 'eliminar'])->whereNumber('numero')->name('consulta.recipes.eliminar');

                // **Récipe de la pareja** (WEB-5.1b): la segunda pestaña de los récipes. Es **uno por
                // consulta** (`recipes_pareja` no tiene `recipe` ni cabecera), así que no lleva número: un solo
                // `POST` lo crea o lo corrige en el lugar y un `DELETE` lo elimina entero. La cédula y el nombre
                // de la pareja son texto libre. Los buscadores del vademécum y de los tratamientos y el alta del
                // medicamento que falta son los de arriba (los mismos endpoints, no se duplican).
                Route::post('/consulta/{consulta}/recipe-pareja', [ConsultaRecipeParejaController::class, 'guardar'])->name('consulta.recipe-pareja.guardar');
                Route::delete('/consulta/{consulta}/recipe-pareja', [ConsultaRecipeParejaController::class, 'eliminar'])->name('consulta.recipe-pareja.eliminar');

                // **Terminar la consulta** (WEB-4.8): la acción primaria del shell. "Cerrar" **no crea un
                // estado nuevo** (PLAN-WEB.md §3.13): es terminar la atención —dejar la cita de la agenda
                // como atendida— y volver a la agenda del día, que es lo que el escritorio hace al cerrar
                // `w_consulta_principal` (su `open` ya había marcado `atendido`). La regla que le da
                // sentido —"cita atendida ⇒ tiene historia"— vive en `App\Actions\Agenda\AtenderCita`, la
                // Action compartida con el sync del móvil, y el rastro queda en `sync_changes` con
                // `source = web`. Va **después** de las rutas que llevan el mismo prefijo para no
                // interceptarlas (el segmento `cerrar` no es un id de imagen, pero el orden es el criterio
                // de este archivo).
                Route::post('/consulta/{consulta}/cerrar', [ConsultaCerrarController::class, 'cerrar'])->name('consulta.cerrar');
            });

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

            // Cumpleaños (WEB-2.9): los que cumplen años hoy y la configuración del envío automático.
            // Es una **herramienta** —no un módulo del menú— y es **provisional hasta WEB-8.2**, que es la
            // pantalla de configuración de notificaciones que se va a llevar la configuración de acá. Se
            // alcanza desde el "Más" de la agenda y desde el inicio. `/cumpleanos` y no
            // `/agenda/cumpleanos` porque el listado **no** es de la agenda (un paciente que cumple años
            // hoy entra aunque no tenga ninguna cita): la agenda sólo ofrece el atajo.
            Route::get('/cumpleanos', [CumpleanosController::class, 'index'])->name('cumpleanos');
            Route::post('/cumpleanos', [CumpleanosController::class, 'enviar'])->name('cumpleanos.enviar');
            Route::patch('/cumpleanos/configuracion', [CumpleanosController::class, 'configuracion'])->name('cumpleanos.configuracion');

            Route::post('/agenda', [CitaController::class, 'crear'])->name('agenda.crear');
            Route::post('/agenda/reordenar', [MostradorController::class, 'reordenar'])->name('agenda.reordenar');
            // "Pasar a cita formal" (WEB-2.11): la Action compartida le asigna la historia al paciente de
            // una cita que todavía no la tiene y la deja enlazada. Va con las acciones del mostrador —es
            // una acción sobre una cita, no una pantalla— y **antes** de `POST /agenda/{cola}`.
            Route::post('/agenda/{cola}/pasar-a-cita-formal', [MostradorController::class, 'pasarACitaFormal'])
                ->name('agenda.pasar-a-cita-formal');
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
