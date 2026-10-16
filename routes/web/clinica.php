<?php

use App\Clinica\Consulta\TextosDelExamen;
use App\Http\Controllers\Clinica\AgendaController;
use App\Http\Controllers\Clinica\BitacoraController;
use App\Http\Controllers\Clinica\CatalogoController;
use App\Http\Controllers\Clinica\DiagnosticoCatalogoController;
use App\Http\Controllers\Clinica\DoctorCatalogoController;
use App\Http\Controllers\Clinica\ExamenCatalogoController;
use App\Http\Controllers\Clinica\FormatoDeImpresionController;
use App\Http\Controllers\Clinica\FormatosEnBlancoController;
use App\Http\Controllers\Clinica\FormatoDeImpresionGuardarController;
use App\Http\Controllers\Clinica\MotivoConsultaCatalogoController;
use App\Http\Controllers\Clinica\TipoExamenCatalogoController;
use App\Http\Controllers\Clinica\MedicamentoCatalogoController;
use App\Http\Controllers\Clinica\TratamientoCatalogoController;
use App\Http\Controllers\Clinica\AyudaController;
use App\Http\Controllers\Clinica\CitaController;
use App\Http\Controllers\Clinica\CitasDelPacienteController;
use App\Http\Controllers\Clinica\ConsultaAntecedentesController;
use App\Http\Controllers\Clinica\ConsultaConstanciaController;
use App\Http\Controllers\Clinica\ConsultaDietaController;
use App\Http\Controllers\Clinica\ConsultaEstudioController;
use App\Http\Controllers\Clinica\ConsultaEstudiosController;
use App\Http\Controllers\Clinica\ConsultaHospitalizacionController;
use App\Http\Controllers\Clinica\ConsultaInformeController;
use App\Http\Controllers\Clinica\ConsultaLaboratorioController;
use App\Http\Controllers\Clinica\ConsultaReferenciasController;
use App\Http\Controllers\Clinica\ConsultaReposoController;
use App\Http\Controllers\Clinica\ImpresionDeDocumentosController;
use App\Http\Controllers\Clinica\ConsultaCerrarController;
use App\Http\Controllers\Clinica\ConsultaTextoDelExamenController;
use App\Http\Controllers\Clinica\ConsultaAnteriorController;
use App\Http\Controllers\Clinica\PlantillaDeTextoController;
use App\Http\Controllers\Clinica\ConsultaController;
use App\Http\Controllers\Clinica\ConsultaDiagnosticosController;
use App\Http\Controllers\Clinica\ConsultaEmbarazoController;
use App\Http\Controllers\Clinica\ConsultaEnfermedadController;
use App\Http\Controllers\Clinica\ConsultaEvaluacionController;
use App\Http\Controllers\Clinica\ConsultaMamasController;
use App\Http\Controllers\Clinica\ConsultaEvolucionController;
use App\Http\Controllers\Clinica\ConsultaImagenesController;
use App\Http\Controllers\Clinica\ConsultaMotivosController;
use App\Http\Controllers\Clinica\ConsultaRecipeParejaController;
use App\Http\Controllers\Clinica\ConsultaRecipeVerificacionController;
use App\Http\Controllers\Clinica\ConsultaRecipesController;
use App\Http\Controllers\Clinica\ConsultorioController;
use App\Http\Controllers\Clinica\ContextoController;
use App\Http\Controllers\Clinica\CumpleanosController;
use App\Http\Controllers\Clinica\HistoriaDelPacienteController;
use App\Http\Controllers\Clinica\HorarioController;
use App\Http\Controllers\Clinica\ImpresionDeRecipeController;
use App\Http\Controllers\Clinica\ListadosController;
use App\Http\Controllers\Clinica\MostradorController;
use App\Http\Controllers\Clinica\MotivoCitaCatalogoController;
use App\Http\Controllers\Clinica\NoLaborableController;
use App\Http\Controllers\Clinica\PacienteController;
use App\Http\Controllers\Clinica\PlantillaDeTextoCatalogoController;
use App\Http\Controllers\Clinica\PlantillasDeMensajeController;
use App\Http\Controllers\Clinica\RecordatorioController;
use App\Http\Controllers\Clinica\RepresentanteController;
use App\Http\Controllers\Clinica\SedeController;
use App\Http\Controllers\Clinica\ShellController;
use App\Http\Controllers\Clinica\UsuarioController;
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

                // «Anterior» (WEB-11.4): copia motivos, diagnósticos o récipes de la consulta anterior de la historia.
                Route::post('/consulta/{consulta}/anterior/{que}', [ConsultaAnteriorController::class, 'copiar'])
                    ->where('que', 'motivos|diagnosticos|recipes')->name('consulta.anterior');

                // «Guardar como plantilla» desde cualquier sección de texto de la consulta (WEB-11.2): JSON, sin recargar.
                Route::post('/plantillas-de-texto', [PlantillaDeTextoController::class, 'guardar'])->name('plantillas.guardar');

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
                // Imágenes de mamas (WEB-11.10): el trazo de cada mama, por consulta; PNG en el disco privado.
                Route::get('/consulta/{consulta}/mamas/{lado}', [ConsultaMamasController::class, 'ver'])->name('consulta.mamas.ver');
                Route::post('/consulta/{consulta}/mamas/{lado}', [ConsultaMamasController::class, 'guardar'])->name('consulta.mamas.guardar');
                Route::delete('/consulta/{consulta}/mamas/{lado}', [ConsultaMamasController::class, 'quitar'])->name('consulta.mamas.quitar');
                Route::post('/consulta/{consulta}/evaluacion', [ConsultaEvaluacionController::class, 'guardar'])->name('consulta.evaluacion.guardar');
                // «Actualizar» (WEB-11.8): arma los resúmenes del examen con lo del formulario, sin guardar.
                Route::post('/consulta/{consulta}/evaluacion/resumen', [ConsultaEvaluacionController::class, 'resumen'])->name('consulta.evaluacion.resumen');

                // Los textos de las solapas del examen (WEB-6.5): la colposcopía, la citología, la
                // histeroscopía y el resultado de exámenes. Las cuatro son **un texto en una columna de
                // `consultas`** (ver `TextosDelExamen`) y las cuatro guardan igual, así que hay **una** ruta: la
                // solapa viaja en la dirección y el `whereIn` del catálogo hace que una que no existe sea un
                // **404** antes de llegar al controlador. La única que además tiene una copia en otra tabla es
                // la colposcopía (`examen_fisico_nuevo.colposcopia`), y eso lo sabe la Action.
                Route::post('/consulta/{consulta}/examen/{solapa}', [ConsultaTextoDelExamenController::class, 'guardar'])
                    ->where('solapa', implode('|', TextosDelExamen::claves(true)))
                    ->name('consulta.examen.texto.guardar');

                // Estudio ecográfico (WEB-6.1): el ítem 5 del panel, con su sección propia
                // (`?seccion=estudio&tipo=…`). El estudio es **una fila por consulta**, llaveada por
                // `numhistoria` + `nroconsulta` (`d_eco_pelvico.srd:6`, `:19`), así que se corrige en el
                // lugar y deja su rastro por columna en el sync. El `{tipo}` de la URL deja lugar a los
                // otros seis estudios del ítem (WEB-6.2 a WEB-6.4) sin ruta nueva.
                Route::post('/consulta/{consulta}/estudio/{tipo}', [ConsultaEstudioController::class, 'guardar'])
                    ->where('tipo', '[a-z]+')->name('consulta.estudio.guardar');

                // Control de embarazo (WEB-6.6): el ítem 6 del panel, con sus pestañas
                // (`?seccion=embarazo&pestana=…`). **El dato es del embarazo y no de la consulta**:
                // `pre_natal_observaciones` se llavea por `historia` + `gesta_clave`, así que el controlador
                // usa el `numhistoria` de la consulta del contexto. El `gesta` del formulario vacío crea la
                // siguiente (*Nueva Gesta*).
                // «Nueva gesta» (WEB-11.15): sólo pregunta cuántos bebés son.
                Route::post('/consulta/{consulta}/embarazo/gesta', [ConsultaEmbarazoController::class, 'crearGesta'])
                    ->name('consulta.embarazo.gesta.crear');
                Route::post('/consulta/{consulta}/embarazo/prenatal', [ConsultaEmbarazoController::class, 'guardarPrenatal'])
                    ->name('consulta.embarazo.prenatal.guardar');

                // Las dos pestañas por fecha del control de embarazo: el laboratorio de la gesta
                // (`prena_exames_b`) y sus controles (`pre_natal_desarrollo_fino`). Las dos llevan la gesta
                // oculta en el formulario, porque la clave es historia + gesta_clave + fecha.
                Route::post('/consulta/{consulta}/embarazo/laboratorio', [ConsultaEmbarazoController::class, 'guardarLaboratorio'])
                    ->name('consulta.embarazo.laboratorio.guardar');
                Route::post('/consulta/{consulta}/embarazo/controles', [ConsultaEmbarazoController::class, 'guardarControl'])
                    ->name('consulta.embarazo.controles.guardar');

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

                // **Imprimir el récipe desde la consulta** (WEB-5.2): la vista previa de la hoja A4 horizontal que
                // imprime el navegador, la de un récipe del paciente (por su número) y la de la pareja. Es la
                // **misma hoja** que imprime la secretaría desde la agenda (más abajo): acá sólo cambia por dónde
                // se entra, y como la consulta es del médico estas dos van en su grupo.
                Route::get('/consulta/{consulta}/recipes/{numero}/imprimir', [ImpresionDeRecipeController::class, 'delPaciente'])
                    ->whereNumber('numero')->name('consulta.recipes.imprimir');
                Route::get('/consulta/{consulta}/recipe-pareja/imprimir', [ImpresionDeRecipeController::class, 'dePareja'])->name('consulta.recipe-pareja.imprimir');

                // **El QR de verificación del récipe** (WEB-5.6): el médico puede anular el QR de un récipe ya impreso (la página
                // pública pasa a decir «Anulado por el médico») y reactivarlo. El QR se crea al imprimir, no acá: un récipe que
                // nunca se imprimió no tiene QR y estas rutas dan 404. La página pública es otra cosa (`routes/verificar.php`).
                Route::post('/consulta/{consulta}/recipes/{numero}/verificacion/anular', [ConsultaRecipeVerificacionController::class, 'anular'])
                    ->whereNumber('numero')->name('consulta.recipes.verificacion.anular');
                Route::post('/consulta/{consulta}/recipes/{numero}/verificacion/reactivar', [ConsultaRecipeVerificacionController::class, 'reactivar'])
                    ->whereNumber('numero')->name('consulta.recipes.verificacion.reactivar');
                Route::post('/consulta/{consulta}/recipe-pareja/verificacion/anular', [ConsultaRecipeVerificacionController::class, 'anularPareja'])
                    ->name('consulta.recipe-pareja.verificacion.anular');
                Route::post('/consulta/{consulta}/recipe-pareja/verificacion/reactivar', [ConsultaRecipeVerificacionController::class, 'reactivarPareja'])
                    ->name('consulta.recipe-pareja.verificacion.reactivar');

                // **Solicitudes de la consulta** (WEB-5.3a): el ítem 11 del panel, con sus pestañas (`?seccion=solicitudes&solicitud=`).
                // Constancia, reposo e informe son **uno por consulta** (la clave primaria del legado) y se guardan/eliminan en la misma
                // URL cambiando el verbo; las referencias son varias, una por médico. Sólo del médico: la secretaría imprime desde la cita.
                Route::post('/consulta/{consulta}/constancia', [ConsultaConstanciaController::class, 'guardar'])->name('consulta.constancia.guardar');
                Route::delete('/consulta/{consulta}/constancia', [ConsultaConstanciaController::class, 'eliminar'])->name('consulta.constancia.eliminar');
                Route::post('/consulta/{consulta}/reposo', [ConsultaReposoController::class, 'guardar'])->name('consulta.reposo.guardar');
                Route::delete('/consulta/{consulta}/reposo', [ConsultaReposoController::class, 'eliminar'])->name('consulta.reposo.eliminar');
                Route::post('/consulta/{consulta}/informe', [ConsultaInformeController::class, 'guardar'])->name('consulta.informe.guardar');
                Route::delete('/consulta/{consulta}/informe', [ConsultaInformeController::class, 'eliminar'])->name('consulta.informe.eliminar');
                // **Hospitalización y dieta / recomendaciones** (WEB-5.3b): las otras dos pestañas de las Solicitudes. También una por consulta
                // (`nrohistoria` + `nroconsulta` y `histroria` + `consulta`), con guardado y baja en la misma URL cambiando el verbo.
                Route::post('/consulta/{consulta}/hospitalizacion', [ConsultaHospitalizacionController::class, 'guardar'])->name('consulta.hospitalizacion.guardar');
                Route::delete('/consulta/{consulta}/hospitalizacion', [ConsultaHospitalizacionController::class, 'eliminar'])->name('consulta.hospitalizacion.eliminar');
                Route::post('/consulta/{consulta}/dieta', [ConsultaDietaController::class, 'guardar'])->name('consulta.dieta.guardar');
                Route::delete('/consulta/{consulta}/dieta', [ConsultaDietaController::class, 'eliminar'])->name('consulta.dieta.eliminar');
                // **Laboratorio y estudios** (WEB-5.4): las dos pestañas de las Solicitudes que arman una orden de varias filas. Se guardan **enteras**
                // (`POST` con la lista completa; lo marcado es lo que queda), se eliminan en la misma URL cambiando el verbo y tienen su buscador JSON
                // del catálogo (`catalogo`) y, en laboratorio, las rutinas (un tipo de examen completo).
                Route::post('/consulta/{consulta}/laboratorio', [ConsultaLaboratorioController::class, 'guardar'])->name('consulta.laboratorio.guardar');
                Route::delete('/consulta/{consulta}/laboratorio', [ConsultaLaboratorioController::class, 'eliminar'])->name('consulta.laboratorio.eliminar');
                Route::get('/consulta/{consulta}/laboratorio/catalogo', [ConsultaLaboratorioController::class, 'catalogo'])->name('consulta.laboratorio.catalogo');
                Route::get('/consulta/{consulta}/laboratorio/rutinas/{tipo}', [ConsultaLaboratorioController::class, 'rutina'])->name('consulta.laboratorio.rutina');
                Route::post('/consulta/{consulta}/estudios', [ConsultaEstudiosController::class, 'guardar'])->name('consulta.estudios.guardar');
                Route::delete('/consulta/{consulta}/estudios', [ConsultaEstudiosController::class, 'eliminar'])->name('consulta.estudios.eliminar');
                Route::get('/consulta/{consulta}/estudios/catalogo', [ConsultaEstudiosController::class, 'catalogo'])->name('consulta.estudios.catalogo');
                // `medicos` (buscador JSON y alta) va antes de las rutas con `{cedula}`.
                Route::get('/consulta/{consulta}/referencias/medicos', [ConsultaReferenciasController::class, 'buscar'])->name('consulta.referencias.buscar');
                Route::post('/consulta/{consulta}/referencias/medicos', [ConsultaReferenciasController::class, 'medicoNuevo'])->name('consulta.referencias.medico-nuevo');
                Route::post('/consulta/{consulta}/referencias', [ConsultaReferenciasController::class, 'agregar'])->name('consulta.referencias.agregar');
                Route::post('/consulta/{consulta}/referencias/{cedula}', [ConsultaReferenciasController::class, 'guardar'])->whereNumber('cedula')->name('consulta.referencias.guardar');
                Route::delete('/consulta/{consulta}/referencias/{cedula}', [ConsultaReferenciasController::class, 'eliminar'])->whereNumber('cedula')->name('consulta.referencias.eliminar');
                // La hoja impresa de un documento (o de «todos»): `?documento=reposo`. La misma que imprime la secretaría desde la cita.
                Route::get('/consulta/{consulta}/documentos/imprimir', [ImpresionDeDocumentosController::class, 'delaConsulta'])->name('consulta.documentos.imprimir');

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

                // **Catálogos del médico** (WEB-8.1a): el vademécum (`vademecum`) y los tratamientos (`recipe_grupo` con
                // sus medicamentos). Sólo del médico, como la consulta: la secretaría no administra catálogos
                // clínicos (403), y todo se acota al `reg_medico` del contexto (lo de otro médico, 404). WEB-8.1b
                // suma acá sus pantallas (`App\Clinica\Catalogos\CatalogosDisponibles`). Quitar un medicamento no
                // borra lo que está en uso; quitar un tratamiento siempre se puede.
                Route::get('/catalogos', [CatalogoController::class, 'indice'])->name('catalogos');
                Route::get('/catalogos/medicamentos', [MedicamentoCatalogoController::class, 'index'])->name('catalogos.medicamentos');
                Route::get('/catalogos/medicamentos/buscar', [MedicamentoCatalogoController::class, 'buscar'])->name('catalogos.medicamentos.buscar');
                Route::post('/catalogos/medicamentos', [MedicamentoCatalogoController::class, 'guardar'])->name('catalogos.medicamentos.guardar');
                Route::delete('/catalogos/medicamentos/{medicamento}', [MedicamentoCatalogoController::class, 'quitar'])->whereNumber('medicamento')->name('catalogos.medicamentos.quitar');
                Route::get('/catalogos/tratamientos', [TratamientoCatalogoController::class, 'index'])->name('catalogos.tratamientos');
                Route::get('/catalogos/tratamientos/nuevo', [TratamientoCatalogoController::class, 'nuevo'])->name('catalogos.tratamientos.nuevo');
                Route::post('/catalogos/tratamientos', [TratamientoCatalogoController::class, 'crear'])->name('catalogos.tratamientos.crear');
                Route::get('/catalogos/tratamientos/{tratamiento}/editar', [TratamientoCatalogoController::class, 'editar'])->whereNumber('tratamiento')->name('catalogos.tratamientos.editar');
                Route::post('/catalogos/tratamientos/{tratamiento}', [TratamientoCatalogoController::class, 'guardar'])->whereNumber('tratamiento')->name('catalogos.tratamientos.guardar');
                Route::delete('/catalogos/tratamientos/{tratamiento}', [TratamientoCatalogoController::class, 'quitar'])->whereNumber('tratamiento')->name('catalogos.tratamientos.quitar');

                // **Catálogos clínicos** (WEB-8.1b): diagnósticos, motivos de consulta, tipos de examen, exámenes y médicos referidos.
                // Los cinco con la misma forma —listado paginado con buscador, alta y edición en un diálogo y «quitar»— y la misma
                // regla de baja que los medicamentos: lo que está en uso no se borra y el aviso dice dónde.
                Route::get('/catalogos/diagnosticos', [DiagnosticoCatalogoController::class, 'index'])->name('catalogos.diagnosticos');
                Route::post('/catalogos/diagnosticos', [DiagnosticoCatalogoController::class, 'guardar'])->name('catalogos.diagnosticos.guardar');
                Route::delete('/catalogos/diagnosticos/{id}', [DiagnosticoCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.diagnosticos.quitar');
                Route::get('/catalogos/motivos-consulta', [MotivoConsultaCatalogoController::class, 'index'])->name('catalogos.motivos-consulta');
                Route::post('/catalogos/motivos-consulta', [MotivoConsultaCatalogoController::class, 'guardar'])->name('catalogos.motivos-consulta.guardar');
                Route::delete('/catalogos/motivos-consulta/{id}', [MotivoConsultaCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.motivos-consulta.quitar');
                // **Motivos de cita** (WEB-8.1c): `motivo_cita` con su precio, su sede y la marca de varias citas pendientes.
                // Absorbió la pantalla `/clinica/agenda/motivos` de WEB-2.17, que ahora redirige acá. El interruptor de la marca
                // (`alternar`) y el alta/edición pasan por la misma Action. El motivo de otro médico, 404; la secretaría, 403.
                Route::get('/catalogos/motivos-cita', [MotivoCitaCatalogoController::class, 'index'])->name('catalogos.motivos-cita');
                Route::post('/catalogos/motivos-cita', [MotivoCitaCatalogoController::class, 'guardar'])->name('catalogos.motivos-cita.guardar');
                Route::patch('/catalogos/motivos-cita/{id}/alternar', [MotivoCitaCatalogoController::class, 'alternar'])->whereNumber('id')->name('catalogos.motivos-cita.alternar');
                Route::delete('/catalogos/motivos-cita/{id}', [MotivoCitaCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.motivos-cita.quitar');
                // La dirección vieja de WEB-2.17 (con su mismo nombre de ruta) lleva a la nueva. Va antes de `/agenda/{cola}`.
                Route::get('/agenda/motivos', [MotivoCitaCatalogoController::class, 'pantallaAnterior'])->name('agenda.motivos');
                Route::get('/catalogos/examenes', [ExamenCatalogoController::class, 'index'])->name('catalogos.examenes');
                Route::post('/catalogos/examenes', [ExamenCatalogoController::class, 'guardar'])->name('catalogos.examenes.guardar');
                Route::delete('/catalogos/examenes/{id}', [ExamenCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.examenes.quitar');
                Route::get('/catalogos/tipos-examen', [TipoExamenCatalogoController::class, 'index'])->name('catalogos.tipos-examen');
                Route::post('/catalogos/tipos-examen', [TipoExamenCatalogoController::class, 'guardar'])->name('catalogos.tipos-examen.guardar');
                Route::delete('/catalogos/tipos-examen/{id}', [TipoExamenCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.tipos-examen.quitar');
                Route::get('/catalogos/medicos-referidos', [DoctorCatalogoController::class, 'index'])->name('catalogos.medicos-referidos');
                Route::post('/catalogos/medicos-referidos', [DoctorCatalogoController::class, 'guardar'])->name('catalogos.medicos-referidos.guardar');
                Route::delete('/catalogos/medicos-referidos/{id}', [DoctorCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.medicos-referidos.quitar');

                // **Plantillas de texto** (WEB-8.4): `formato_print` del médico —las plantillas de la evolución, del informe médico, de las dietas…—,
                // con la misma forma que los otros catálogos clínicos (listado con buscador y filtro por tipo, alta y edición en un diálogo y
                // «quitar»). Una plantilla se copia a la consulta al elegirla, así que siempre se puede quitar.
                Route::get('/catalogos/plantillas-texto', [PlantillaDeTextoCatalogoController::class, 'index'])->name('catalogos.plantillas-texto');
                Route::post('/catalogos/plantillas-texto', [PlantillaDeTextoCatalogoController::class, 'guardar'])->name('catalogos.plantillas-texto.guardar');
                Route::delete('/catalogos/plantillas-texto/{id}', [PlantillaDeTextoCatalogoController::class, 'quitar'])->whereNumber('id')->name('catalogos.plantillas-texto.quitar');

                // **Formato de impresión del médico** (WEB-8.2): el membrete, el logo, el estilo de cada bloque, la firma y el sello con
                // los que sale el récipe y los demás documentos, más la hoja de prueba. Sólo del médico, como los catálogos: la
                // secretaría imprime con el formato pero no lo configura (403). El formato es del médico y no del consultorio: se
                // guarda bajo su registro principal, igual que el móvil. Cada sección tiene su ruta de guardado.
                Route::get('/formato', [FormatoDeImpresionController::class, 'index'])->name('formato');
                Route::get('/formato/vista-previa', [FormatoDeImpresionController::class, 'vistaPrevia'])->name('formato.vista-previa');
                // **Formatos en blanco** (WEB-5.5b): cada documento sin datos de paciente, para rellenar a mano. Sólo lee; la clave la acota el `where`.
                // El guion entra en la clave (`eco-obstetrico` y su hermano del 3.er trimestre, WEB-6.2): con `[a-z]+` la ruta no matcheaba y salía un 404 antes de llegar al controlador.
                Route::get('/formato/en-blanco/{formato}', [FormatosEnBlancoController::class, 'ver'])->where('formato', '[a-z][a-z-]*')->name('formato.en-blanco');
                Route::post('/formato/membrete', [FormatoDeImpresionGuardarController::class, 'membrete'])->name('formato.membrete');
                Route::post('/formato/logo', [FormatoDeImpresionGuardarController::class, 'logo'])->name('formato.logo');
                Route::post('/formato/estilo', [FormatoDeImpresionGuardarController::class, 'estilo'])->name('formato.estilo');
                Route::post('/formato/firma', [FormatoDeImpresionGuardarController::class, 'firma'])->name('formato.firma');
                Route::post('/formato/restablecer', [FormatoDeImpresionGuardarController::class, 'restablecer'])->name('formato.restablecer');

                // **Mensajes a pacientes** (WEB-8.4): las plantillas del recordatorio de cita y del saludo de cumpleaños, con sus etiquetas,
                // el contador de caracteres y de SMS y la vista previa. Sólo del médico (la secretaría manda los mensajes pero no escribe sus
                // palabras: 403). `{plantilla}` es `cita` o `cumple`; la vista previa es un JSON que no guarda nada.
                Route::get('/mensajes', [PlantillasDeMensajeController::class, 'index'])->name('mensajes');
                Route::post('/mensajes/{plantilla}', [PlantillasDeMensajeController::class, 'guardar'])->where('plantilla', 'cita|cumple')->name('mensajes.guardar');
                Route::post('/mensajes/{plantilla}/restablecer', [PlantillasDeMensajeController::class, 'restablecer'])->where('plantilla', 'cita|cumple')->name('mensajes.restablecer');
                Route::post('/mensajes/{plantilla}/vista-previa', [PlantillasDeMensajeController::class, 'vistaPrevia'])->where('plantilla', 'cita|cumple')->name('mensajes.vista-previa');

                // **Usuarios del consultorio** (WEB-8.5): el médico gestiona a su secretaria (alta con clave temporal, restablecer la clave,
                // bloquear, desbloquear y quitar el acceso). Sólo del médico: la secretaría recibe 403. `{usuario}` es una cuenta
                // solo-secretaría vinculada a los registros del médico del contexto; cualquier otra responde 404.
                Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios');
                Route::post('/usuarios', [UsuarioController::class, 'crear'])->name('usuarios.crear');
                Route::post('/usuarios/{usuario}/restablecer', [UsuarioController::class, 'restablecer'])->whereNumber('usuario')->name('usuarios.restablecer');
                Route::post('/usuarios/{usuario}/bloquear', [UsuarioController::class, 'bloquear'])->whereNumber('usuario')->name('usuarios.bloquear');
                Route::post('/usuarios/{usuario}/desbloquear', [UsuarioController::class, 'desbloquear'])->whereNumber('usuario')->name('usuarios.desbloquear');
                Route::delete('/usuarios/{usuario}', [UsuarioController::class, 'quitar'])->whereNumber('usuario')->name('usuarios.quitar');

                // **Bitácora** (WEB-8.5): qué cambió en el registro del médico y quién lo hizo. De sólo lectura y sólo del médico.
                Route::get('/bitacora', [BitacoraController::class, 'index'])->name('bitacora');

                // **Listados clínicos entre fechas** (WEB-6.8): los reportes del médico sobre lo que ya está en su
                // registro. Es **una pantalla parametrizada** —el rango, los filtros y la grilla— y no una pantalla
                // por reporte: hoy entra el **listado de embarazos / partos** (el *Listado de Partos* del escritorio,
                // `w_reportes_consultas.srw:449`) y los otros dos de la tarea suman su lectura y su ViewModel sin
                // tocar estas dos rutas. Sólo el médico: son reportes de su consulta, así que la secretaría recibe
                // **403** (mismo grupo que la consulta y los catálogos clínicos).
                Route::get('/listados/imprimir', [ListadosController::class, 'imprimir'])->name('listados.imprimir');
                Route::get('/listados', [ListadosController::class, 'index'])->name('listados');
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

            // Cumpleaños (WEB-2.9): los que cumplen años hoy y la configuración del envío automático.
            // Es una **herramienta** —no un módulo del menú— y es **provisional hasta WEB-8.2**, que es la
            // pantalla de configuración de notificaciones que se va a llevar la configuración de acá. Se
            // alcanza desde el "Más" de la agenda y desde el inicio. `/cumpleanos` y no
            // `/agenda/cumpleanos` porque el listado **no** es de la agenda (un paciente que cumple años
            // hoy entra aunque no tenga ninguna cita): la agenda sólo ofrece el atajo.
            Route::get('/cumpleanos', [CumpleanosController::class, 'index'])->name('cumpleanos');
            Route::post('/cumpleanos', [CumpleanosController::class, 'enviar'])->name('cumpleanos.enviar');
            Route::patch('/cumpleanos/configuracion', [CumpleanosController::class, 'configuracion'])->name('cumpleanos.configuracion');

            // **Imprimir el récipe de una cita atendida** (WEB-5.2): lo que la secretaría hace con la fila de
            // la agenda, sin entrar a la consulta (que es sólo del médico). `recipes` es la lista para elegir
            // cuál (se salta si la cita tiene uno solo) y `recipes/imprimir` la vista previa: la misma hoja que
            // imprime el médico desde la consulta. Sólo **imprime**: no lista medicamentos ni deja editar nada.
            // Desde WEB-5.3a es «Imprimir documentos»: la lista lleva los récipes **y** la constancia, el reposo, las referencias y el
            // informe de esa consulta (`?documento=recipe-1|reposo|referencia-3|todos`).
            Route::get('/agenda/{cola}/documentos', [ImpresionDeDocumentosController::class, 'listaDeLaCita'])->name('agenda.documentos');
            Route::get('/agenda/{cola}/documentos/imprimir', [ImpresionDeDocumentosController::class, 'imprimirDeLaCita'])->name('agenda.documentos.imprimir');
            // Cómo sale la hoja (modo y nombre del medicamento) es **del médico del contexto**: lo guarda quien
            // imprime —el médico o su asistente— y los dos ven lo mismo la próxima vez.
            Route::post('/recipes/opciones-de-impresion', [ImpresionDeRecipeController::class, 'guardarOpciones'])->name('recipes.opciones');

            // **"Ayúdanos a mejorar"** (ROADMAP §9): el comentario del médico o de su secretaría, y la lista de
            // los suyos con nuestra respuesta. La bandeja donde los recibimos es del panel (`/admin`).
            Route::get('/ayuda', [AyudaController::class, 'index'])->name('ayuda');
            Route::post('/ayuda', [AyudaController::class, 'guardar'])->name('ayuda.guardar');

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
