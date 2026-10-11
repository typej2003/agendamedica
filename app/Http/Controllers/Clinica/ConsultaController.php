<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\CitaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\EvolucionDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\ExamenesDeLaConsulta;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\EnfermedadActualVista;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use App\Clinica\Consulta\Vistas\EvolucionVista;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Consulta\Vistas\SeccionDeDiagnosticosVista;
use App\Clinica\Consulta\Vistas\SeccionDeExamenesVista;
use App\Clinica\Consulta\Vistas\SeccionDeMotivosVista;
use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * **La consulta** (F4): el shell de la consulta de un paciente —el encabezado y el panel de 13 ítems del
 * legado— y, desde **WEB-4.2**, el contenido de sus secciones (motivos de consulta, enfermedad actual,
 * evaluación física, antecedentes, exámenes e imágenes, diagnóstico y evolución), que se abren **dentro
 * de la misma pantalla** con `?seccion=`.
 *
 * Entrar es **siempre por una acción del médico**: "Atender" desde la agenda, que abre —o crea— la
 * consulta del día (`App\Actions\Consulta\AbrirConsulta`). Por eso no hay una ruta que liste consultas ni
 * un módulo en el menú lateral: el panel del médico es la agenda del día, y el shell se abre sobre la
 * consulta que se está atendiendo. Una lista de consultas tendría un solo elemento por día y no
 * respondería ninguna pregunta que la agenda no responda ya.
 *
 * Las **secciones no son pantallas**: el legado tiene el panel de secciones y un área de trabajo al lado,
 * y el ítem elegido cambia el contenido sin salir de la consulta (wiki `04-historia-clinica.md`). Por eso
 * el shell es un solo controlador y una sola vista, y lo que cambia es qué ViewModel de sección arma
 * `seccion()` — con `?seccion=` en la URL, que es lo que hace que el enlace del panel se pueda compartir,
 * recargar y volver atrás.
 *
 * La lectura se filtra por los `reg_medico` del contexto **y** por el vínculo del paciente
 * (`medico_pacientes`), que es lo que dice de quién es la historia: una consulta de otro consultorio
 * responde **404** sin confirmar que exista (misma regla que la ficha y la historia del paciente). Lo
 * resuelve `App\Clinica\Consulta\ConsultasDelContexto`, que es también lo que usan las acciones que
 * escriben sobre la consulta.
 *
 * Controlador propio y no un método más de `MostradorController`: el mostrador **escribe** sobre la cita
 * (confirmar, atender, cobrar) y esto **lee** la consulta para mostrarla. Es la misma partición que ya
 * hacen la agenda y sus acciones.
 */
class ConsultaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function ver(
        Request $request,
        Consulta $consulta,
        MotivosDeLaConsulta $motivos,
        ExamenFisicoDeLaConsulta $examen,
        AntecedentesDelPaciente $antecedentes,
        DiagnosticosDeLaConsulta $diagnosticos,
        EvolucionDeLaConsulta $evolucion,
        ExamenesDeLaConsulta $examenes,
        ImagenesDeLaConsulta $imagenes,
        CitaDeLaConsulta $citas,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        // El `{consulta}` de la ruta no se usa como modelo binding: se vuelve a resolver contra el
        // contexto, que es lo que decide si la consulta es de este consultorio (404 si no).
        $consulta = $this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']);

        $paciente = $this->consultas->paciente($consulta, $registros);

        abort_unless($paciente !== null, 404);

        // El día de la consulta, ya interpretado una sola vez: es lo que muestra el encabezado y lo que
        // elige la agenda a la que se vuelve.
        $dia = $consulta->fecha ? Carbon::parse($consulta->fecha) : FechaDelConsultorio::hoy();

        $seccion = (string) $request->query('seccion', '');
        $urlConsulta = route('clinica.consulta', $consulta->id);

        return view('clinica.consulta.ver', [
            'vm' => new ConsultaShellVista(
                $paciente,
                (int) $consulta->nroconsulta,
                $dia->toDateString(),
                route('clinica.pacientes.ver', $paciente->id),
                route('clinica.pacientes.historia', $paciente->id),
                // Volver a la agenda del día **de la consulta**: es donde el médico la estaba atendiendo,
                // y sirve igual para la de hoy que para una anterior (una consulta vieja se abre desde la
                // historia del paciente, y volver a la agenda de su día es una respuesta válida).
                route('clinica.agenda', [
                    'vista' => 'dia',
                    'fecha' => $dia->toDateString(),
                    'sede' => 'todas',
                ]),
                $urlConsulta,
                $seccion,
                route('clinica.consulta.cerrar', $consulta->id),
                // "Terminar la consulta" (WEB-4.8) se ofrece **sólo para la consulta de hoy**: el
                // escritorio marca `atendido` nada más que la cita del día (`w_consulta_principal.srw:4418`),
                // y marcar hoy la cita de una consulta vieja no sería terminar la atención de nadie.
                // La bandera sale de acá y no de la vista (guía de arquitectura: el rol y el estado no se
                // consultan en el Blade).
                $this->esDeHoy($dia) && $this->hayCitaTerminable($consulta, $dia, (int) $paciente->id, $citas),
            ),
            // La sección activa: una sola, la que el panel abrió. El shell sin sección muestra su estado
            // vacío. Se llama `contenido` y no `seccion` porque `seccion` ya es el nombre del **ViewModel**
            // para los parciales de cada sección (el shell y la sección no pueden llamarse igual).
            'contenido' => $this->seccion($seccion, $consulta, $contexto['reg_medico'], $motivos, $examen, $antecedentes, $diagnosticos, $evolucion, $examenes),
            // Las tarjetas que acompañan a una sección y no son parte de su contenido: el buscador del
            // catálogo de antecedentes (WEB-4.4) y la subida de imágenes de los exámenes (WEB-4.7). Las dos
            // son herramientas de la sección —una agrega antecedentes, la otra sube un archivo—, no su
            // cuerpo, y por eso viajan aparte.
            'catalogo' => $this->catalogoDeAntecedentes($seccion, $consulta, $contexto['reg_medico'], $antecedentes),
            'imagenes' => $this->imagenesDeLaConsulta($seccion, $consulta, $contexto['reg_medico'], $imagenes),
        ]);
    }

    /**
     * ¿La consulta es de hoy en el consultorio? Es la condición de "Terminar la consulta" (WEB-4.8).
     *
     * Se compara contra `FechaDelConsultorio::hoy()` y no contra `now()`: "hoy" para el negocio es el día
     * del consultorio, y en un consultorio de Venezuela (UTC-4) la fecha del servidor se adelanta cuatro
     * horas (misma trampa que el "Atender" de la agenda, `LectorDeAgenda`).
     */
    private function esDeHoy(Carbon $dia): bool
    {
        return $dia->toDateString() === FechaDelConsultorio::hoy()->toDateString();
    }

    /**
     * ¿Hay una cita de la agenda que "Terminar la consulta" pueda marcar? Sin cita no hay nada que
     * terminar más allá de la consulta misma, y con la cita movida por el escritorio la fila es la vieja
     * (`CitaEncontrada::terminable`), así que la pantalla no ofrece la acción.
     */
    private function hayCitaTerminable(Consulta $consulta, Carbon $dia, int $pacienteId, CitaDeLaConsulta $citas): bool
    {
        return $citas->de((int) $consulta->numhistoria, $dia, (string) $consulta->reg_medico, null, $pacienteId) !== null;
    }

    /**
     * El ViewModel de la sección que se está mirando, o `null` si no se eligió ninguna (o el valor de la
     * URL no corresponde a una sección que exista).
     *
     * Las URLs son **de la acción**, no de la pantalla: "Agregar" y "Quitar" postean a rutas propias
     * —porque escriben— y vuelven acá. La vista no arma ninguna.
     */
    private function seccion(
        string $seccion,
        Consulta $consulta,
        string $regMedico,
        MotivosDeLaConsulta $motivos,
        ExamenFisicoDeLaConsulta $examen,
        AntecedentesDelPaciente $antecedentes,
        DiagnosticosDeLaConsulta $diagnosticos,
        EvolucionDeLaConsulta $evolucion,
        ExamenesDeLaConsulta $examenes,
    ): ?object {
        $numero = (int) $consulta->numhistoria;
        $nroconsulta = (int) $consulta->nroconsulta;

        if ($seccion === ConsultaShellVista::SECCION_MOTIVOS) {
            $buscar = trim((string) request()->query('buscar', ''));

            return new SeccionDeMotivosVista(
                $motivos->buscar($regMedico, $buscar),
                $motivos->deLaConsulta($regMedico, $numero, $nroconsulta),
                $buscar,
                route('clinica.consulta.motivos.buscar', $consulta->id),
                route('clinica.consulta.motivos.agregar', $consulta->id),
                // "Quitar" lleva el id de la fila en la URL: se arma con `route()` por fila (la ruta tiene
                // dos parámetros y concatenar un id al final cae en otra ruta distinta).
                fn (int $fila) => route('clinica.consulta.motivos.quitar', [$consulta->id, $fila]),
                route('clinica.consulta.motivos.crear', $consulta->id),
                // El diálogo de alta se abre solo si el POST falló: el error viaja en la bolsa y sin
                // esto el médico tendría que volver a abrirlo para corregir lo que escribió.
                session()->has('crear_motivo'),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_ENFERMEDAD) {
            return new EnfermedadActualVista(
                $consulta->enfermedadactual,
                route('clinica.consulta.enfermedad.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ENFERMEDAD]),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_EVALUACION) {
            return new EvaluacionFisicaVista(
                $examen->de($consulta),
                route('clinica.consulta.evaluacion.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION]),
                // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                (array) old(),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_ANTECEDENTES) {
            $delPaciente = $antecedentes->delPaciente($regMedico, (string) $consulta->numhistoria);

            return new SeccionDeAntecedentesVista(
                $delPaciente,
                route('clinica.consulta.antecedentes.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]),
                // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                (array) old(),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_DIAGNOSTICO) {
            $buscar = trim((string) request()->query('buscar', ''));

            return new SeccionDeDiagnosticosVista(
                // Los diagnósticos de **esta** consulta: la fila se llavea por `nrohistoria` +
                // `nroconsulta` + `codediagnostico` (`DiagnosticosDeLaConsulta`).
                $diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta),
                $diagnosticos->buscar($regMedico, $buscar),
                // Lo tildado del catálogo viaja en la query (el buscador es un `GET`): son los códigos que
                // el botón de guardar agrega a la consulta.
                array_values(array_filter((array) request()->query('agregar', []), 'is_string')),
                $buscar,
                route('clinica.consulta.diagnosticos.guardar', $consulta->id),
                // La base del buscador y del "Cancelar": esta misma sección, sin query.
                route('clinica.consulta', [$consulta->id]),
                route('clinica.consulta.diagnosticos.crear', $consulta->id),
                (array) old(),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_EVOLUCION) {
            return new EvolucionVista(
                // El texto del escritorio se lee **tal cual** (los "vacíos" del legado se limpian en la
                // lectura, `EvolucionDeLaConsulta`) y las plantillas son las de su tabla `formato_print`.
                $evolucion->de($consulta),
                $evolucion->plantillas($regMedico),
                route('clinica.consulta.evolucion.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVOLUCION]),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_EXAMENES) {
            return new SeccionDeExamenesVista(
                // Los exámenes de **esta** consulta: la fila se llavea por `nrohistoria` + `nroconsulta`
                // (`examen_paciente`, el `dw_12` de la pestaña de laboratorio del escritorio).
                $examenes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                // La observación del conjunto (`examen_obs.observacion`), que no es el resultado de una
                // fila: es el texto que el escritorio deja para el grupo de exámenes.
                $examenes->observacion($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                $examenes->tieneCatalogo($regMedico),
            );
        }

        return null;
    }

    /**
     * El ViewModel de la **subida de imágenes** de la consulta (WEB-4.7), o `null` si la sección activa no
     * es la de exámenes.
     *
     * Es la otra mitad del ítem 7 y la única parte de la consulta que mueve un archivo: el archivo va al
     * disco privado `clinica` y se sirve por la ruta autorizada de `ConsultaImagenesController`, que
     * comprueba que la historia es de este médico. La tarjeta se arma aparte de la lista de exámenes
     * porque son dos cosas distintas —una lee lo que hizo el escritorio, la otra escribe—, igual que la
     * tarjeta del catálogo de antecedentes.
     */
    private function imagenesDeLaConsulta(
        string $seccion,
        Consulta $consulta,
        string $regMedico,
        ImagenesDeLaConsulta $imagenes,
    ): ?object {
        if ($seccion !== ConsultaShellVista::SECCION_EXAMENES) {
            return null;
        }

        return new ImagenesDeLaConsultaVista(
            $imagenes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
            route('clinica.consulta.examenes.subir', $consulta->id),
            // Una función por fila: la ruta lleva el id de la imagen y armar la URL concatenando ids cae
            // en otra ruta (trampa 22 del repo, que ya costó una vez en los motivos).
            fn (int $fila) => route('clinica.consulta.examenes.descargar', [$consulta->id, $fila]),
            fn (int $fila) => route('clinica.consulta.examenes.quitar', [$consulta->id, $fila]),
        );
    }

    /**
     * El ViewModel del **buscador del catálogo de antecedentes**, o `null` si la sección activa no es la de
     * antecedentes. Es una lectura propia —el catálogo del médico y los tipos, que no dependen de la
     * consulta— y por eso no viaja adentro del ViewModel de la sección: la vista grande son los
     * antecedentes del paciente y esta tarjeta es la herramienta para agregarlos.
     */
    private function catalogoDeAntecedentes(
        string $seccion,
        Consulta $consulta,
        string $regMedico,
        AntecedentesDelPaciente $antecedentes,
    ): ?object {
        if ($seccion !== ConsultaShellVista::SECCION_ANTECEDENTES) {
            return null;
        }

        $buscar = trim((string) request()->query('buscar', ''));
        $tipo = trim((string) request()->query('tipo', ''));
        $delPaciente = $antecedentes->delPaciente($regMedico, (string) $consulta->numhistoria);

        return new SeccionDeAntecedentesCatalogoVista(
            $antecedentes->catalogo($regMedico, $buscar, $tipo),
            $delPaciente->pluck('codigo')->all(),
            $antecedentes->tipos(),
            // La base del enlace "Agregar" de cada fila: vuelve a **esta misma sección** (el `?seccion=`
            // lo agrega el ViewModel) con el código tildado.
            route('clinica.consulta', [$consulta->id]),
            route('clinica.consulta.antecedentes.crear', $consulta->id),
            $buscar,
            $tipo,
            // El `?…` de la URL: es lo que dice qué quedó tildado para agregar (`?agregar=…`).
            request()->query(),
            // La base del `action` del guardado: el ViewModel le agrega la sección (para que la vuelta del
            // POST caiga acá) y los pendientes del buscador como query.
            route('clinica.consulta.antecedentes.guardar', $consulta->id),
        );    }
}
