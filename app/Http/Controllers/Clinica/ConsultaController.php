<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\EvolucionDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\EnfermedadActualVista;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use App\Clinica\Consulta\Vistas\EvolucionVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Consulta\Vistas\SeccionDeDiagnosticosVista;
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
 * evaluación física, antecedentes, diagnóstico y evolución), que se abren **dentro de la misma pantalla**
 * con `?seccion=`.
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
            ),
            // La sección activa: una sola, la que el panel abrió. El shell sin sección muestra su estado
            // vacío. Se llama `contenido` y no `seccion` porque `seccion` ya es el nombre del **ViewModel**
            // para los parciales de cada sección (el shell y la sección no pueden llamarse igual).
            'contenido' => $this->seccion($seccion, $consulta, $contexto['reg_medico'], $motivos, $examen, $antecedentes, $diagnosticos, $evolucion),
            // La tarjeta del buscador del catálogo, que sólo tiene la sección de antecedentes (WEB-4.4).
            'catalogo' => $this->catalogoDeAntecedentes($seccion, $consulta, $contexto['reg_medico'], $antecedentes),
        ]);
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

        return null;
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
