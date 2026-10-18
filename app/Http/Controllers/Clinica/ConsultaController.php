<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\CitaDeLaConsulta;
use App\Clinica\Consulta\TagDeImagen;
use App\Clinica\Consulta\TextoDelExamen;
use App\Clinica\Consulta\TipoDeAntecedentes;
use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\DibujosDeColposcopiaDeLaConsulta;
use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\EvolucionDeLaConsulta;
use App\Clinica\Consulta\FurDelControlPrenatal;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\ResumenMedicoDeLaConsulta;
use App\Clinica\Consulta\Vistas\TextoDelExamenVista;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\ExamenesDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaPareja;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\PlantillasDelTexto;
use App\Clinica\Consulta\RecipeDeLaPareja;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\TextosDelExamen;
use App\Clinica\Consulta\Vistas\ConstanciaVista;
use App\Clinica\Consulta\Vistas\DietaVista;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\Vistas\EcoCardiogramaFetalVista;
use App\Clinica\Consulta\Vistas\EcoAbdominalVista;
use App\Clinica\Consulta\Vistas\EcoDopplerVista;
use App\Clinica\Consulta\Vistas\EcoGinecologicoVista;
use App\Clinica\Consulta\Vistas\EcoMamarioVista;
use App\Clinica\Consulta\Vistas\EcoObstetricoVista;
use App\Clinica\Consulta\Vistas\EcoObstetricoSegundoVista;
use App\Clinica\Consulta\Vistas\EcoObstetricoTerceroVista;
use App\Clinica\Consulta\Vistas\EnfermedadActualVista;
use App\Clinica\Consulta\ResumenesAnteriores;
use App\Clinica\Consulta\Vistas\DibujosDeMamasVista;
use App\Clinica\Consulta\Vistas\DibujosDeColposcopiaVista;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use App\Clinica\Consulta\Vistas\EvolucionVista;
use App\Clinica\Consulta\Vistas\HospitalizacionVista;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use App\Clinica\Consulta\Vistas\InformeVista;
use App\Clinica\Consulta\Vistas\OrdenVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\ReferenciasVista;
use App\Clinica\Consulta\Vistas\ReposoVista;
use App\Clinica\Consulta\Vistas\SeccionDeSolicitudesVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Consulta\Vistas\SeccionDeDiagnosticosVista;
use App\Clinica\Consulta\Vistas\SeccionDeEstudioVista;
use App\Clinica\Consulta\Vistas\SeccionDeEvaluacionVista;
use App\Clinica\Consulta\Vistas\SeccionDeExamenesVista;
use App\Clinica\Consulta\Vistas\SeccionDeMotivosVista;
use App\Clinica\Consulta\Vistas\PestanasDeRecipes;
use App\Clinica\Consulta\Vistas\SeccionDeRecipeDeLaParejaVista;
use App\Clinica\Consulta\Vistas\SeccionDeRecipesVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Embarazo\Gesta;
use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Clinica\Embarazo\InformePrenatal;
use App\Clinica\Embarazo\Vistas\FechasDelEmbarazoVista;
use App\Clinica\Embarazo\Vistas\PestanasDelEmbarazo;
use App\Clinica\Embarazo\Vistas\PrenatalVista;
use App\Clinica\Embarazo\Vistas\ReporteVista;
use App\Clinica\Embarazo\Vistas\SeccionDeEmbarazoVista;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Verificacion\VerificacionesDeLaConsulta;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\PrenaExamenB;
use App\Models\PreNatalDesarrolloFino;
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

    public function __construct(
        private ConsultasDelContexto $consultas,
        private MembreteDelMedico $membretes,
        private ProximaCitaDelRecipe $proximaCita,
        private VerificacionesDeLaConsulta $verificaciones,
    ) {
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
        RecipesDeLaConsulta $recipes,
        RecipeDeLaPareja $pareja,
        ConstanciaDeLaConsulta $constancias,
        ReposoDeLaConsulta $reposos,
        ReferenciasDeLaConsulta $referencias,
        InformeDeLaConsulta $informes,
        DoctoresDelMedico $doctores,
        HospitalizacionDeLaConsulta $hospitalizaciones,
        DietaDeLaConsulta $dietas,
        LaboratorioDeLaConsulta $laboratorio,
        EstudiosDeLaConsulta $estudios,
        CatalogoParaPedirExamenes $catalogoDeExamenes,
        EcoGinecologico $eco,
        EcoObstetrico $ecoObstetrico,
        EcoObstetricoTercero $ecoObstetricoTercero,
        EcoDoppler $ecoDoppler,
        EcoCardiogramaFetal $ecoFetal,
        FurDelControlPrenatal $furPrenatal,
        GestasDeLaHistoria $gestas,
        InformePrenatal $informePrenatal,
        ResumenMedicoDeLaConsulta $resumenes,
        EcoMamario $ecoMamario,
        EcoAbdominal $ecoAbdominal,
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

        // Las alertas (WEB-11.6): todas las del paciente, fijas en la pantalla; y el diálogo de aviso **una vez por atención** (el id
        // queda en la sesión y `MostradorController` lo olvida al darle «Atender» otra vez).
        $alertas = $antecedentes->delPaciente($contexto['reg_medico'], (string) $consulta->numhistoria)
            ->where('alerta', true)
            ->map(fn ($fila) => ['nombre' => $fila->nombre, 'detalle' => $fila->detalle])
            ->values()
            ->all();
        $vistas = (array) $request->session()->get('alertas_vistas', []);
        $avisar = $alertas !== [] && ! in_array((int) $consulta->id, $vistas, true);
        if ($avisar) {
            $request->session()->push('alertas_vistas', (int) $consulta->id);
        }

        // El grupo «Consulta» del menú lateral (revisión de Alexander): el armazón necesita saber qué consulta se está
        // atendiendo y qué sección está abierta para armar sus ítems y marcar el activo. Se comparte acá —y no se lee
        // de la ruta en el compositor— porque el `id` del paciente y la sección ya están resueltos en este método.
        view()->share('consulta_en_curso', [
            'id' => (int) $consulta->id,
            'seccion' => $seccion,
        ]);

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
                // El navegador 1…N entre las consultas de la historia (WEB-11.5).
                $this->consultas->delPaciente($consulta)->map(fn ($fila) => [
                    'id' => $fila->id,
                    'nroconsulta' => $fila->nroconsulta,
                    'url' => route('clinica.consulta', $fila->id),
                ])->all(),
                (int) $consulta->id,
                $alertas,
                $avisar,
            ),
            // La sección activa: una sola, la que el panel abrió. El shell sin sección muestra su estado
            // vacío. Se llama `contenido` y no `seccion` porque `seccion` ya es el nombre del **ViewModel**
            // para los parciales de cada sección (el shell y la sección no pueden llamarse igual).
            'contenido' => $seccion === ConsultaShellVista::SECCION_SOLICITUDES
                ? $this->solicitudes($consulta, $registros, $dia, $paciente, $constancias, $reposos, $referencias, $informes, $doctores, $hospitalizaciones, $dietas, $laboratorio, $estudios, $catalogoDeExamenes, $resumenes)
                : $this->seccion($seccion, $consulta, $contexto['reg_medico'], $motivos, $examen, $antecedentes, $diagnosticos, $evolucion, $examenes, $recipes, $pareja, $dia, (int) $paciente->id, $contexto['medico'], $eco, $ecoObstetrico, $ecoObstetricoTercero, $ecoDoppler, $ecoFetal, $furPrenatal, $gestas, $informePrenatal, $ecoMamario, $ecoAbdominal, $doctores),
            // Las tarjetas que acompañan a una sección y no son parte de su contenido: el buscador del
            // catálogo de antecedentes (WEB-4.4) y la subida de imágenes de los exámenes (WEB-4.7). Las dos
            // son herramientas de la sección —una agrega antecedentes, la otra sube un archivo—, no su
            // cuerpo, y por eso viajan aparte.
            'catalogo' => $this->catalogoDeAntecedentes($seccion, $consulta, $contexto['reg_medico'], $antecedentes),
            'imagenes' => $this->imagenesDeLaConsulta($seccion, $consulta, $contexto['reg_medico'], $imagenes),
            // La tarjeta que agrega la colposcopía (A-13): sus dos lienzos del cuello uterino, con su base y su trazo.
            'dibujos' => $this->dibujosDeLaColposcopia($seccion, (string) request()->query('pestana', ''), $consulta),
        ]);
    }

    /**
     * Lo obligatorio que le falta a la ficha para cerrar la consulta (WEB-11.25): depende de la especialidad del contexto (en pediatría
     * se pide el teléfono del representante y no hay cédula).
     *
     * @return array<int,string>
     */
    private function datosQueFaltan(object $paciente, string $regMedico, ?string $numhistoria): array
    {
        return \App\Clinica\Pacientes\FiliacionPendiente::de(
            $paciente, $regMedico, $numhistoria, request()->attributes->get('contexto_clinico')['specialty']?->id,
        );
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
        RecipesDeLaConsulta $recipes,
        RecipeDeLaPareja $pareja,
        Carbon $dia,
        int $pacienteId,
        Medico $medico,
        EcoGinecologico $eco,
        EcoObstetrico $ecoObstetrico,
        EcoObstetricoTercero $ecoObstetricoTercero,
        EcoDoppler $ecoDoppler,
        EcoCardiogramaFetal $ecoFetal,
        FurDelControlPrenatal $furPrenatal,
        GestasDeLaHistoria $gestas,
        InformePrenatal $informePrenatal,
        EcoMamario $ecoMamario,
        EcoAbdominal $ecoAbdominal,
        DoctoresDelMedico $doctores,
    ): ?object {
        $numero = (int) $consulta->numhistoria;
        $nroconsulta = (int) $consulta->nroconsulta;

        if ($seccion === ConsultaShellVista::SECCION_MOTIVOS) {
            $buscar = trim((string) request()->query('buscar', ''));

            $urlSeccion = route('clinica.consulta', [$consulta->id]);
            $conservar = ['seccion' => ConsultaShellVista::SECCION_MOTIVOS] + ($buscar !== '' ? ['buscar' => $buscar] : []);
            $pagina = $motivos->pagina($regMedico, $buscar, (int) request()->query('pagina_catalogo', 1), $urlSeccion, $conservar);

            return new SeccionDeMotivosVista(
                $pagina->getCollection(),
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
                $pagina,
                $motivos->existeEnElCatalogo($regMedico, $buscar),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_MOTIVOS]),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_DATOS) {
            // Los datos personales (WEB-11.25): la ficha del paciente de esta historia, editable acá mismo.
            $ficha = app(\App\Clinica\Pacientes\PacientesDelConsultorio::class)->editable($regMedico, $pacienteId);
            abort_if($ficha === null, 404);

            return new \App\Clinica\Consulta\Vistas\SeccionDeDatosPersonalesVista(
                new \App\Clinica\Pacientes\Vistas\FormularioDePacienteVista(
                    $ficha['paciente'],
                    $ficha['numhistoria'],
                    app(\App\Clinica\Pacientes\CatalogoDeSeguros::class)->de($regMedico),
                ),
                $ficha['paciente'],
                $ficha['numhistoria'],
                $this->datosQueFaltan($ficha['paciente'], $regMedico, $ficha['numhistoria']),
                (int) $consulta->id,
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_ENFERMEDAD) {
            return new EnfermedadActualVista(
                $consulta->enfermedadactual,
                route('clinica.consulta.enfermedad.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ENFERMEDAD]),
                PlantillasDelTexto::de($regMedico, 'EA')->all(),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_EVALUACION) {
            // Las **solapas del examen** (WEB-6.5): el `&pestana=` elige cuál, como el `&tipo=` del estudio.
            // Están la evaluación física (WEB-4.3, el examen general y el ginecológico juntos) y las cuatro
            // solapas de texto (colposcopía, citología, histeroscopía y resultado de exámenes).
            $pestana = PestanasDelExamen::normalizar((string) request()->query('pestana', ''));

            $url = fn (string $clave) => route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_EVALUACION,
                'pestana' => $clave,
            ]);

            // La lectura de las solapas de texto comparte la lectura del examen (`examen_fisico_nuevo`) con la
            // evaluación física: la copia de la colposcopía es esa misma fila.
            $texto = new TextoDelExamen($examen);

            // La hoja de la solapa, si tiene (la colposcopía y la histeroscopía; la citología y el resultado de
            // exámenes no tienen informe en el escritorio).
            $documento = TextosDelExamen::documento($pestana);

            return new SeccionDeEvaluacionVista(
                $pestana,
                new PestanasDelExamen($url),
                $pestana === PestanasDelExamen::MAMAS
                    ? new DibujosDeMamasVista(
                        fn (string $lado) => route('clinica.consulta.mamas.ver', [$consulta->id, $lado]),
                        fn (string $lado) => route('clinica.consulta.mamas.guardar', [$consulta->id, $lado]),
                        \App\Models\DibujoDeMama::where([
                            'reg_medico' => $consulta->reg_medico,
                            'nrohistoria' => (int) $consulta->numhistoria,
                            'nroconsulta' => (int) $consulta->nroconsulta,
                        ])->pluck('lado')->all(),
                    )
                    : (PestanasDelExamen::esDeTexto($pestana)
                    ? new TextoDelExamenVista(
                        $pestana,
                        $texto->de($consulta, $pestana),
                        $texto->plantillas($regMedico, $pestana),
                        route('clinica.consulta.examen.texto.guardar', [$consulta->id, $pestana]),
                        $url($pestana),
                        // La hoja del informe (WEB-6.5): la impresión de documentos de la consulta, con la
                        // clave del examen.
                        $documento === null
                            ? ''
                            : route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]),
                        // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                        (array) old(),
                    )
                    : new EvaluacionFisicaVista(
                        $examen->de($consulta),
                        route('clinica.consulta.evaluacion.guardar', $consulta->id),
                        $url($pestana),
                        (array) old(),
                        route('clinica.consulta.evaluacion.resumen', $consulta->id),
                        PlantillasDelTexto::de($regMedico, 'RF')->all(),
                        PlantillasDelTexto::de($regMedico, 'EG')->all(),
                        $pestana,
                        $pestana === PestanasDelExamen::GINECOLOGICO ? (new ResumenesAnteriores())->de($consulta, 'total_g') : [],
                    )),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_ESTUDIO) {
            // El tipo del estudio viaja en la URL (`&tipo=`): el contenedor muestra los que existen hoy
            // (el ginecológico de WEB-6.1, los dos obstétricos de WEB-6.2, el Doppler y el ecocardiograma
            // fetal de WEB-6.3) y los demás llegan con las tareas siguientes de F6.
            $tipo = TiposDeEstudio::normalizar((string) request()->query('tipo', ''));

            // El eco abdominal no se ofrece en ginecología: el botón está oculto en el escritorio (WEB-11.14, Alexander).
            $especialidad = request()->attributes->get('contexto_clinico')['specialty']?->slug;
            $tipos = new TiposDeEstudio(fn (string $clave) => route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => $clave,
            ]), $especialidad === 'ginecologia-y-obstetricia' ? [TiposDeEstudio::ABDOMINAL] : []);

            // El del 2.º/3.er trimestre es el mismo contenedor con su propio ViewModel (su mapa tiene 55
            // columnas en seis bloques, contra las 38 del primero).
            if ($tipo === TiposDeEstudio::TERCERO || $tipo === TiposDeEstudio::SEGUNDO) {
                // El 2.º trimestre (WEB-11.14) es la misma pantalla sobre la tabla hermana `eco_obstetrico_tercer_2`.
                $esSegundo = $tipo === TiposDeEstudio::SEGUNDO;
                $vistaDelTrimestre = $esSegundo ? EcoObstetricoSegundoVista::class : EcoObstetricoTerceroVista::class;
                $lecturaDelTrimestre = $esSegundo ? app(EcoObstetricoSegundo::class) : $ecoObstetricoTercero;

                return new SeccionDeEstudioVista(
                    $tipo,
                    $tipos,
                    new $vistaDelTrimestre(
                        $lecturaDelTrimestre->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $esSegundo ? DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO : DocumentoImpreso::ECO_OBSTETRICO_TERCERO]),
                        null,
                        null,
                        (array) old(),
                    ),
                );
            }

            // El eco Doppler perinatal (WEB-6.3): su mapa, su tabla llaveada por `historia` + `consulta` y
            // su propio parcial. Su ficha (`fur`, `gesta`, `para`) la teclea el médico: su ventana del
            // escritorio no la copia del control prenatal.
            if ($tipo === TiposDeEstudio::DOPPLER) {
                return new SeccionDeEstudioVista(
                    $tipo,
                    $tipos,
                    new EcoDopplerVista(
                        $ecoDoppler->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_DOPPLER]),
                        null,
                        null,
                        (array) old(),
                    ),
                );
            }

            // El ecocardiograma fetal (WEB-6.3): su mapa, su tabla llaveada por `historia` + `consulta` y su
            // propio parcial. La pantalla lo abre con los dos textos que la ventana del escritorio precarga
            // cuando la consulta todavía no tiene fila (`deParaLaPantalla`); la impresión, en cambio, lee lo
            // guardado (`de`), así que una consulta sin fila no ofrece ninguna hoja: por eso el ViewModel sabe
            // si el estudio ya existe.
            if ($tipo === TiposDeEstudio::FETAL) {
                $guardado = $ecoFetal->filaDe($consulta) !== null;

                return new SeccionDeEstudioVista(
                    $tipo,
                    $tipos,
                    new EcoCardiogramaFetalVista(
                        $ecoFetal->deParaLaPantalla($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_CARDIOGRAMA_FETAL]),
                        null,
                        null,
                        (array) old(),
                        $guardado,
                    ),
                );
            }

            // El eco mamario (WEB-6.4): su mapa, su tabla llaveada por `historia` + `consulta` y su propio
            // parcial. Su único desplegable es el de médicos (`referido`, que guarda la cédula): sus opciones
            // salen de la libreta del médico —la misma lectura de la pestaña *Referencias*— y no del mapa del
            // dato. El estudio no tiene ficha del embarazo: lo único que no cuenta es su fecha.
            if ($tipo === TiposDeEstudio::MAMARIO) {
                return new SeccionDeEstudioVista(
                    $tipo,
                    $tipos,
                    new EcoMamarioVista(
                        $ecoMamario->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_MAMARIO]),
                        $doctores->delCatalogo($this->registrosDelMedico($medico))
                            ->map(fn (object $doctor) => ['valor' => (string) $doctor->cedula, 'texto' => $doctor->nombre])
                            ->all(),
                        (array) old(),
                    ),
                );
            }

            // El eco abdominal (WEB-6.4), el séptimo y último tipo del ítem 5: su mapa, su tabla llaveada por
            // `historia` + `consulta` y su propio parcial. Es una ecografía abdominal general (la misma tabla
            // y el mismo DataWindow los usan cirugía y pediatría). Comparte con el mamario el desplegable de
            // médicos (`referido`) y la ficha corta (sólo la fecha); sus otros cuatro desplegables son códigos
            // del DataWindow y salen del mapa. `prostata` y `vejiga_urina` no se ofrecen: son de urología y en
            // ginecología van ocultas.
            if ($tipo === TiposDeEstudio::ABDOMINAL) {
                return new SeccionDeEstudioVista(
                    $tipo,
                    $tipos,
                    new EcoAbdominalVista(
                        $ecoAbdominal->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_ABDOMINAL]),
                        $doctores->delCatalogo($this->registrosDelMedico($medico))
                            ->map(fn (object $doctor) => ['valor' => (string) $doctor->cedula, 'texto' => $doctor->nombre])
                            ->all(),
                        (array) old(),
                    ),
                );
            }

            return new SeccionDeEstudioVista(
                $tipo,
                $tipos,
                $tipo === TiposDeEstudio::OBSTETRICO
                    ? new EcoObstetricoVista(
                        $ecoObstetrico->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        // La hoja del informe del obstétrico: la impresión de documentos de la consulta, con
                        // la clave del estudio (WEB-6.2).
                        route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_OBSTETRICO]),
                        $furPrenatal->de($regMedico, (int) $consulta->numhistoria),
                        $gestas->actual($regMedico, (int) $consulta->numhistoria)?->clave(),
                        (array) old(),
                    )
                    : new EcoGinecologicoVista(
                    $eco->de($consulta),
                    route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                    route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                    // La hoja del informe: la impresión de documentos de la consulta, con la clave del
                    // eco (WEB-6.1). Es la misma ruta que usan la constancia, el reposo y los demás.
                    route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_PELVICO]),
                    // La FUR del control prenatal de la historia, como la busca el escritorio al entrar a
                    // la pestaña *Ginecologico* (`w_consulta_principal.srw:21391-21405`).
                    $furPrenatal->de($regMedico, (int) $consulta->numhistoria),
                    // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                    (array) old(),
                ),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_EMBARAZO) {
            // Las pestañas del ítem 6: hoy sólo *Prenatal*, así que lo demás cae en ella (como los tipos del
            // estudio). El control prenatal es **de la historia**, no de la consulta: se lee y se escribe por
            // el `numhistoria` de esta consulta.
            $pestana = PestanasDelEmbarazo::normalizar((string) request()->query('pestana', ''));
            $historia = (int) $consulta->numhistoria;
            $deLaHistoria = $gestas->de($regMedico, $historia, $dia);

            // Qué gesta se mira: la que pide la URL (`&gesta=2`), la actual (la de mayor número) o ninguna
            // cuando se está creando una nueva (`&nueva=1`).
            $pedida = (int) request()->query('gesta', 0);
            $elegida = request()->boolean('nueva')
                ? null
                : ($pedida > 0
                    ? collect($deLaHistoria)->first(fn ($gesta) => $gesta->clave() === $pedida)
                    : $gestas->actual($regMedico, $historia, $dia));

            $url = fn (array $parametros = []) => route('clinica.consulta', array_merge(
                [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO, 'pestana' => $pestana],
                $parametros,
            ));

            $urlPestanas = fn (string $clave) => route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_EMBARAZO,
                'pestana' => $clave,
            ]);

            // La gesta a la que pertenecen las filas: la que se mira o, si todavía no hay ninguna, la
            // siguiente (el número que le va a tocar cuando se guarde la primera).
            $claveDeLaGesta = $elegida?->clave() ?? $gestas->siguienteClave($regMedico, $historia);

            // Cada pestaña tiene su ViewModel: *Prenatal* es la de las gestas y las otras dos —*Laboratorio*
            // y *Controles*— son una fila por fecha, que comparten ViewModel y parcial.
            if ($pestana === PestanasDelEmbarazo::REPORTE) {
                // El reporte: el resumen de lo que sale en la hoja «CONTROL DE EMBARAZO» y el botón que la
                // imprime. La hoja la arma el motor de documentos, con `&gesta=` en la URL.
                $informe = $informePrenatal->de($regMedico, $historia, $elegida?->clave(), $dia);

                $pestanaVm = new ReporteVista(
                    partes: $informe,
                    gestas: $deLaHistoria,
                    urlImprimir: route('clinica.consulta.documentos.imprimir', [
                        $consulta->id,
                        'documento' => DocumentoImpreso::CONTROL_DE_EMBARAZO,
                    ]) . ($claveDeLaGesta > 0 ? '&gesta=' . $claveDeLaGesta : ''),
                    urlVolver: $url(),
                    urlGesta: fn (int $clave) => $url(['gesta' => $clave]),
                );
            } elseif ($pestana === PestanasDelEmbarazo::LABORATORIO || $pestana === PestanasDelEmbarazo::CONTROLES) {
                $esLaboratorio = $pestana === PestanasDelEmbarazo::LABORATORIO;
                $modelo = $esLaboratorio ? PrenaExamenB::class : PreNatalDesarrolloFino::class;
                $campos = $modelo::campos();

                $filas = $modelo::query()
                    ->where('reg_medico', $regMedico)
                    ->where('historia', $historia)
                    ->where('gesta_clave', $claveDeLaGesta)
                    ->orderBy('fecha')
                    ->get()
                    ->map(function ($fila) use ($campos) {
                        $valores = [];
                        foreach ($campos as $campo) {
                            $valores[$campo['columna']] = Gesta::texto($fila->{$campo['columna']});
                        }

                        return ['fecha' => (string) $fila->fecha, 'valores' => $valores];
                    })
                    ->all();

                $pestanaVm = new FechasDelEmbarazoVista(
                    titulo: $esLaboratorio ? 'Laboratorio del embarazo' : 'Controles del embarazo',
                    icono: $esLaboratorio ? 'bi-droplet-half' : 'bi-graph-up',
                    campos: $campos,
                    filas: $filas,
                    fechaElegida: (string) request()->query('fecha', ''),
                    gestaClave: $claveDeLaGesta,
                    urlGuardar: route($esLaboratorio
                        ? 'clinica.consulta.embarazo.laboratorio.guardar'
                        : 'clinica.consulta.embarazo.controles.guardar', $consulta->id),
                    urlVolver: $url(),
                    urlNueva: $url(['gesta' => $claveDeLaGesta, 'nueva' => 1]),
                    urlFecha: fn (string $fecha) => $url(['gesta' => $claveDeLaGesta, 'fecha' => $fecha]),
                    ayuda: $esLaboratorio
                        ? 'Cada fecha es un laboratorio de esta gesta: el hemograma, la glicemia, la orina y las serologías que el embarazo pide. Se guarda con el botón; si sales sin guardar, el navegador te avisa.'
                        : 'Cada fecha es un control de esta gesta: el peso, la tensión, la altura uterina, la placenta y las medidas del feto. Se guarda con el botón; si sales sin guardar, el navegador te avisa.',
                    aviso: 'Hay cambios sin guardar. Si sales ahora, se pierden.',
                    old: (array) old(),
                );
            } else {
                $pestanaVm = new PrenatalVista(
                    $deLaHistoria,
                    $elegida,
                    route('clinica.consulta.embarazo.prenatal.guardar', $consulta->id),
                    $url(),
                    $url(['nueva' => 1]),
                    fn (int $clave) => $url(['gesta' => $clave]),
                    $dia,
                    // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                    (array) old(),
                );
            }

            return new SeccionDeEmbarazoVista($pestana, new PestanasDelEmbarazo($urlPestanas), $pestanaVm);
        }

        if ($seccion === ConsultaShellVista::SECCION_ANTECEDENTES) {
            $delPaciente = $antecedentes->delPaciente($regMedico, (string) $consulta->numhistoria);
            $tipos = $antecedentes->tipos();

            return new SeccionDeAntecedentesVista(
                $delPaciente,
                $tipos,
                // El chip elegido (A-06): el `?tipo=` de la pantalla, resuelto con el mismo criterio que la
                // columna del catálogo, para que las dos columnas no puedan mostrar tipos distintos.
                TipoDeAntecedentes::elegido((string) request()->query('tipo', ''), $delPaciente, $tipos),
                route('clinica.consulta.antecedentes.guardar', $consulta->id),
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]),
                // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                (array) old(),
                // Lo que el buscador dejó tildado: los chips lo conservan en su URL.
                array_values(array_filter((array) request()->query('agregar', []), 'is_string')),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_DIAGNOSTICO) {
            $buscar = trim((string) request()->query('buscar', ''));
            $pendientes = array_values(array_filter((array) request()->query('agregar', []), 'is_string'));
            $conservar = ['seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO]
                + ($buscar !== '' ? ['buscar' => $buscar] : [])
                + ($pendientes !== [] ? ['agregar' => $pendientes] : []);
            $pagina = $diagnosticos->pagina(
                $regMedico, $buscar, (int) request()->query('pagina_catalogo', 1),
                route('clinica.consulta', [$consulta->id]), $conservar,
            );

            return new SeccionDeDiagnosticosVista(
                // Los diagnósticos de **esta** consulta: la fila se llavea por `nrohistoria` +
                // `nroconsulta` + `codediagnostico` (`DiagnosticosDeLaConsulta`).
                $diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta),
                $pagina->getCollection(),
                // Lo tildado del catálogo viaja en la query (el buscador es un `GET`): son los códigos que
                // el botón de guardar agrega a la consulta.
                $pendientes,
                $buscar,
                route('clinica.consulta.diagnosticos.guardar', $consulta->id),
                // La base del buscador y del "Cancelar": esta misma sección, sin query.
                route('clinica.consulta', [$consulta->id]),
                route('clinica.consulta.diagnosticos.crear', $consulta->id),
                (array) old(),
                $pagina,
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_TRATAMIENTO) {
            // El tratamiento (ítem TRATAMIENTO del legado): un texto en `consultas.laringe` con sus plantillas
            // `TR`. Es la misma pantalla que las solapas de texto del examen —`TextoDelExamenVista` y su
            // parcial—, con su propio ítem del panel; guarda por la misma ruta y vuelve a esta sección.
            $texto = new TextoDelExamen($examen);
            $clave = TextosDelExamen::TRATAMIENTO;
            $volver = route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_TRATAMIENTO]);

            return new TextoDelExamenVista(
                $clave,
                $texto->de($consulta, $clave),
                $texto->plantillas($regMedico, $clave),
                route('clinica.consulta.examen.texto.guardar', [$consulta->id, $clave]),
                $volver,
                '',
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
                // *Solicitudes → Laboratorio* (WEB-5.4): donde se piden y se corrigen esos mismos exámenes.
                route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::LABORATORIO]),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_RECIPES) {
            return $this->recipes($consulta, $regMedico, $recipes, $pareja, $dia, $pacienteId, $medico);
        }

        return null;
    }

    /**
     * El ViewModel de la sección **Solicitudes** (WEB-5.3a, ítem 11): la pestaña elegida con `?solicitud=` (laboratorio, estudios, constancia, reposo,
     * referencias, hospitalización, informe o dieta; cualquier otro valor abre la constancia) y los datos de esa pestaña ya leídos.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     */
    private function solicitudes(
        Consulta $consulta,
        array $registros,
        Carbon $dia,
        object $paciente,
        ConstanciaDeLaConsulta $constancias,
        ReposoDeLaConsulta $reposos,
        ReferenciasDeLaConsulta $referencias,
        InformeDeLaConsulta $informes,
        DoctoresDelMedico $doctores,
        HospitalizacionDeLaConsulta $hospitalizaciones,
        DietaDeLaConsulta $dietas,
        LaboratorioDeLaConsulta $laboratorio,
        EstudiosDeLaConsulta $estudios,
        CatalogoParaPedirExamenes $catalogoDeExamenes,
        ResumenMedicoDeLaConsulta $resumenes,
    ): SeccionDeSolicitudesVista {
        $id = $consulta->id;
        $activa = PestanasDeSolicitudes::normalizar(request()->query('solicitud'));
        $old = (array) old();

        $pestanas = new PestanasDeSolicitudes(
            fn (string $clave) => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $clave]),
        );

        // La hoja impresa de cada documento: la misma página que imprime la secretaría desde la cita.
        $imprimir = fn (string $clave) => route('clinica.consulta.documentos.imprimir', [$id, 'documento' => $clave]);

        if ($activa === PestanasDeSolicitudes::PAREJA) {
            $vista = $this->laboratorioDeLaPareja($consulta, app(LaboratorioDeLaPareja::class), $catalogoDeExamenes, $old, $imprimir(DocumentoImpreso::LABORATORIO_PAREJA));
        } elseif ($activa === PestanasDeSolicitudes::LABORATORIO) {
            $vista = $this->laboratorio($consulta, $laboratorio, $catalogoDeExamenes, $old, $imprimir('laboratorio'));
        } elseif ($activa === PestanasDeSolicitudes::ESTUDIOS) {
            $vista = $this->estudios($consulta, $estudios, $catalogoDeExamenes, $old, $imprimir('estudios'));
        } elseif ($activa === PestanasDeSolicitudes::REPOSO) {
            $fila = $reposos->fila($consulta);
            $vista = new ReposoVista(
                $fila?->numdias,
                $fila?->fdesde?->toDateString(),
                $fila?->obser_reposo,
                $reposos->esReal($fila),
                $dia->toDateString(),
                ['guardar' => route('clinica.consulta.reposo.guardar', $id), 'eliminar' => route('clinica.consulta.reposo.eliminar', $id), 'imprimir' => $imprimir('reposo')],
                $old,
            );
        } elseif ($activa === PestanasDeSolicitudes::REFERENCIAS) {
            $vista = new ReferenciasVista(
                $referencias->deLaConsulta($consulta, $registros),
                $doctores->especialidades($registros),
                [
                    'buscar' => route('clinica.consulta.referencias.buscar', $id),
                    'agregar' => route('clinica.consulta.referencias.agregar', $id),
                    'medicoNuevo' => route('clinica.consulta.referencias.medico-nuevo', $id),
                ],
                fn (int $cedula) => route('clinica.consulta.referencias.guardar', [$id, $cedula]),
                fn (int $cedula) => route('clinica.consulta.referencias.eliminar', [$id, $cedula]),
                fn (int $cedula) => $imprimir('referencia-' . $cedula),
                $old,
                session()->has('crear_medico'),
            );
        } elseif ($activa === PestanasDeSolicitudes::INFORME) {
            $fila = $informes->fila($consulta);
            $vista = new InformeVista(
                $fila?->para,
                $fila?->descripcion,
                $fila?->fe_cha?->toDateString(),
                $informes->esReal($fila),
                FechaDelConsultorio::hoy()->toDateString(),
                $informes->plantillas((string) $consulta->reg_medico),
                $informes->anteriores($consulta),
                [
                    'guardar' => route('clinica.consulta.informe.guardar', $id),
                    'eliminar' => route('clinica.consulta.informe.eliminar', $id),
                    'imprimir' => $imprimir('informe'),
                    // El resumen médico (WEB-5.3c): la otra hoja de esta pestaña —el escritorio las tiene
                    // juntas, `tabpage_14`, con un radio—. La web lo ofrece sólo si tiene algo que resumir.
                    'imprimirResumen' => $imprimir(DocumentoImpreso::RESUMEN_MEDICO),
                ],
                $old,
                $resumenes->de($consulta)->esDocumento(),
            );
        } elseif ($activa === PestanasDeSolicitudes::HOSPITALIZACION) {
            $fila = $hospitalizaciones->fila($consulta);
            $vista = new HospitalizacionVista(
                $fila?->hospital,
                $fila?->diagprev,
                $fila?->dieta,
                $hospitalizaciones->esReal($fila),
                $constancias->textoAutomatico($consulta),
                ['guardar' => route('clinica.consulta.hospitalizacion.guardar', $id), 'eliminar' => route('clinica.consulta.hospitalizacion.eliminar', $id), 'imprimir' => $imprimir('hospitalizacion')],
                $old,
            );
        } elseif ($activa === PestanasDeSolicitudes::DIETA) {
            $fila = $dietas->fila($consulta);
            $vista = new DietaVista(
                $fila?->dieta,
                $fila?->descripcion,
                $dietas->esReal($fila),
                $dietas->plantillas((string) $consulta->reg_medico),
                ['guardar' => route('clinica.consulta.dieta.guardar', $id), 'eliminar' => route('clinica.consulta.dieta.eliminar', $id), 'imprimir' => $imprimir('dieta')],
                $old,
            );
        } else {
            $fila = $constancias->fila($consulta);
            $vista = new ConstanciaVista(
                trim(trim((string) $paciente->nombres) . ' ' . trim((string) $paciente->apellidos)),
                $dia->toDateString(),
                $constancias->textoAutomatico($consulta),
                $constancias->diagnosticosEditados($fila),
                $constancias->observacion($fila),
                $constancias->tieneContenidoPropio($fila),
                ['guardar' => route('clinica.consulta.constancia.guardar', $id), 'eliminar' => route('clinica.consulta.constancia.eliminar', $id), 'imprimir' => $imprimir('constancia')],
                $old,
            );
        }

        return new SeccionDeSolicitudesVista($activa, $pestanas, $vista);
    }

    /**
     * La pestaña **Laboratorio** (WEB-5.4): la orden de exámenes de la consulta, el catálogo del que se pide y las rutinas (los tipos de
     * examen con exámenes). Si el último guardado falló la validación, la lista es la que quedó en el formulario (`old`), no la guardada.
     *
     * @param  array<string,mixed>  $old
     */
    private function laboratorio(Consulta $consulta, LaboratorioDeLaConsulta $laboratorio, CatalogoParaPedirExamenes $catalogo, array $old, string $urlImprimir): OrdenVista
    {
        $id = $consulta->id;
        $regMedico = (string) $consulta->reg_medico;

        $filas = $laboratorio->filas($consulta);
        $observacion = $laboratorio->observacion($consulta);

        $items = $filas->map(fn (object $fila) => [
            'codigo' => $fila->codigo,
            'nombre' => $fila->nombre,
            'tipo' => $fila->tipo,
            'aviso' => $fila->nombre === $fila->codigo ? 'No está en tu catálogo: se muestra el código.' : null,
        ])->all();

        return OrdenVista::laboratorio(
            $this->itemsDelFormulario($old, 'examenes', $items, $regMedico, false, $catalogo),
            array_key_exists('observacion', $old) ? ($old['observacion'] ?? null) : $observacion,
            $laboratorio->esReal($filas, $observacion),
            $catalogo->hay($regMedico, false),
            $catalogo->rutinas($regMedico),
            [
                'guardar' => route('clinica.consulta.laboratorio.guardar', $id),
                'eliminar' => route('clinica.consulta.laboratorio.eliminar', $id),
                'imprimir' => $urlImprimir,
                'catalogo' => route('clinica.consulta.laboratorio.catalogo', $id),
                'rutina' => route('clinica.consulta.laboratorio.rutina', [$id, '__TIPO__']),
                'administrar' => route('clinica.catalogos.examenes'),
                // El botón «Pareja» (WEB-11.21): su orden aparte.
                'pareja' => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::PAREJA]),
            ],
        );
    }

    /**
     * La orden de laboratorio de la **pareja** (WEB-11.21): la pantalla del laboratorio con la C.I. y el nombre de la pareja. Se abre con el botón
     * «Pareja» del laboratorio. Si el último guardado falló, lo que quedó en el formulario (`old`) manda sobre lo guardado.
     *
     * @param  array<string,mixed>  $old
     */
    private function laboratorioDeLaPareja(Consulta $consulta, LaboratorioDeLaPareja $pareja, CatalogoParaPedirExamenes $catalogo, array $old, string $urlImprimir): OrdenVista
    {
        $id = $consulta->id;
        $regMedico = (string) $consulta->reg_medico;

        $filas = $pareja->filas($consulta);
        $datos = $pareja->datos($consulta);

        $items = $filas->map(fn (object $fila) => [
            'codigo' => $fila->codigo,
            'nombre' => $fila->nombre,
            'tipo' => $fila->tipo,
            'aviso' => $fila->nombre === $fila->codigo ? 'No está en tu catálogo: se muestra el código.' : null,
        ])->all();

        return OrdenVista::pareja(
            $this->itemsDelFormulario($old, 'examenes', $items, $regMedico, false, $catalogo),
            [
                'cedula' => array_key_exists('cedula', $old) ? ($old['cedula'] ?? null) : $datos['cedula'],
                'nombre' => array_key_exists('nombre', $old) ? ($old['nombre'] ?? null) : $datos['nombre'],
            ],
            array_key_exists('observacion', $old) ? ($old['observacion'] ?? null) : $datos['observacion'],
            $pareja->esReal($filas, $datos),
            $catalogo->hay($regMedico, false),
            $catalogo->rutinas($regMedico),
            [
                'guardar' => route('clinica.consulta.laboratorio-pareja.guardar', $id),
                'eliminar' => route('clinica.consulta.laboratorio-pareja.eliminar', $id),
                'imprimir' => $urlImprimir,
                // El buscador y las rutinas son los de la orden de la paciente: el catálogo es el mismo.
                'catalogo' => route('clinica.consulta.laboratorio.catalogo', $id),
                'rutina' => route('clinica.consulta.laboratorio.rutina', [$id, '__TIPO__']),
                'administrar' => route('clinica.catalogos.examenes'),
                'volver' => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::LABORATORIO]),
            ],
        );
    }

    /**
     * La pestaña **Estudios** (WEB-5.4): la solicitud de estudios de imágenes de la consulta y su catálogo (los exámenes de tipo `002`).
     *
     * @param  array<string,mixed>  $old
     */
    private function estudios(Consulta $consulta, EstudiosDeLaConsulta $estudios, CatalogoParaPedirExamenes $catalogo, array $old, string $urlImprimir): OrdenVista
    {
        $id = $consulta->id;
        $regMedico = (string) $consulta->reg_medico;

        $filas = $estudios->filas($consulta);
        $observacion = $estudios->observacion($consulta);

        $items = $filas->map(fn (object $fila) => [
            'codigo' => $fila->codigo,
            'nombre' => $fila->nombre,
            'tipo' => null,
            'aviso' => $fila->nombre === $fila->codigo ? 'No está en tu catálogo: se muestra el código.' : null,
        ])->all();

        return OrdenVista::estudios(
            $this->itemsDelFormulario($old, 'estudios', $items, $regMedico, true, $catalogo),
            array_key_exists('observacion', $old) ? ($old['observacion'] ?? null) : $observacion,
            $estudios->esReal($filas, $observacion),
            $catalogo->hay($regMedico, true),
            [
                'guardar' => route('clinica.consulta.estudios.guardar', $id),
                'eliminar' => route('clinica.consulta.estudios.eliminar', $id),
                'imprimir' => $urlImprimir,
                'catalogo' => route('clinica.consulta.estudios.catalogo', $id),
                'administrar' => route('clinica.catalogos.examenes'),
            ],
        );
    }

    /**
     * Las filas del editor de una orden: las guardadas, o —si el último guardado falló la validación y volvió con el formulario— las que
     * había en él, con el nombre resuelto contra lo guardado y contra el catálogo (el formulario sólo manda códigos).
     *
     * @param  array<string,mixed>  $old
     * @param  array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}>  $guardadas
     * @return array<int,array{codigo:string, nombre:string, tipo:?string, aviso:?string}>
     */
    private function itemsDelFormulario(array $old, string $campo, array $guardadas, string $regMedico, bool $estudios, CatalogoParaPedirExamenes $catalogo): array
    {
        if (! array_key_exists('observacion', $old)) {
            return $guardadas;
        }

        $codigos = array_values(array_unique(array_filter(array_map(fn ($codigo) => trim((string) $codigo), (array) ($old[$campo] ?? [])), fn (string $codigo) => $codigo !== '')));
        $conocidas = collect($guardadas)->keyBy('codigo');
        $delCatalogo = $catalogo->resolver($regMedico, $estudios, array_values(array_diff($codigos, $conocidas->keys()->all())));

        return array_map(function (string $codigo) use ($conocidas, $delCatalogo) {
            if ($conocidas->has($codigo)) {
                return $conocidas->get($codigo);
            }

            $examen = $delCatalogo[$codigo] ?? null;

            return [
                'codigo' => $codigo,
                'nombre' => $examen['nombre'] ?? $codigo,
                'tipo' => $examen['tipo'] ?? null,
                'aviso' => $examen === null ? 'No está en tu catálogo: se muestra el código.' : null,
            ];
        }, $codigos);
    }

    /**
     * El ViewModel de los **récipes** de la consulta (WEB-5.1): la lista de los que tiene, el editor de uno
     * (`?recipe=nuevo` o `?recipe=2`) o —**WEB-5.1b**— el **récipe de la pareja** (`?recipe=pareja`), la segunda
     * pestaña de la sección.
     *
     * Un `?recipe=` que no es `nuevo`, `pareja` ni el número de un récipe de **esta** consulta da **404**: no hay
     * nada que editar y no se confirma qué récipes existen en otra consulta.
     */
    private function recipes(Consulta $consulta, string $regMedico, RecipesDeLaConsulta $recipes, RecipeDeLaPareja $pareja, Carbon $dia, int $pacienteId, Medico $medico): object
    {
        $editando = request()->query('recipe');
        $editando = is_string($editando) && $editando !== '' ? $editando : null;

        $id = $consulta->id;

        $urlLista = route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]);
        $pestanas = new PestanasDeRecipes(
            $urlLista,
            route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'recipe' => ConsultaShellVista::RECIPE_PAREJA]),
        );

        if ($editando === ConsultaShellVista::RECIPE_PAREJA) {
            return (new SeccionDeRecipeDeLaParejaVista(
                // El récipe de la pareja de **esta** consulta (`recipes_pareja`: una por consulta, sin cabecera),
                // o `null` si todavía no tiene. No se mezcla con los récipes del paciente.
                $pareja->de($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                [
                    'guardar' => route('clinica.consulta.recipe-pareja.guardar', $id),
                    'eliminar' => route('clinica.consulta.recipe-pareja.eliminar', $id),
                    // La caja de herramientas es la misma del récipe del paciente: mismos buscadores y misma alta.
                    'medicamentos' => route('clinica.consulta.recipes.medicamentos', $id),
                    'tratamientos' => route('clinica.consulta.recipes.tratamientos', $id),
                    'crearMedicamento' => route('clinica.consulta.recipes.medicamentos.crear', $id),
                ],
                $pestanas,
                // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
                (array) old(),
            ))->conImpresion($this->impresion($consulta, $pacienteId, $medico))
                ->conVerificaciones($this->estadoDeLasVerificaciones($consulta));
        }

        if ($editando !== null) {
            abort_unless($editando === 'nuevo' || (ctype_digit($editando) && $recipes->existe($consulta, (int) $editando)), 404);
        }

        return (new SeccionDeRecipesVista(
            // Los récipes de **esta** consulta, agrupados como el legado (`nrohistoria` + `nroconsulta` +
            // `recipe`, con el `NULL` del escritorio como el récipe 1).
            $recipes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
            $editando === null ? null : ($editando === 'nuevo' ? 'nuevo' : (string) (int) $editando),
            $dia->toDateString(),
            [
                'lista' => $urlLista,
                'nuevo' => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'recipe' => 'nuevo']),
                'guardarNuevo' => route('clinica.consulta.recipes.crear', $id),
                'medicamentos' => route('clinica.consulta.recipes.medicamentos', $id),
                'tratamientos' => route('clinica.consulta.recipes.tratamientos', $id),
                'crearMedicamento' => route('clinica.consulta.recipes.medicamentos.crear', $id),
            ],
            // Las rutas con dos parámetros se arman con una función por récipe (trampa 22 de `TRAMPAS.md`).
            fn (int $numero) => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'recipe' => $numero]),
            fn (int $numero) => route('clinica.consulta.recipes.guardar', [$id, $numero]),
            fn (int $numero) => route('clinica.consulta.recipes.eliminar', [$id, $numero]),
            // Lo escrito en un POST que falló la validación, para no borrarlo (guía de UI §3).
            (array) old(),
            $pestanas,
        ))->conImpresion($this->impresion($consulta, $pacienteId, $medico))
            ->conVerificaciones($this->estadoDeLasVerificaciones($consulta));
    }

    /**
     * El estado del QR de verificación de cada récipe de la consulta y a dónde van «Anular» y «Reactivar» (WEB-5.6). El QR se crea
     * al imprimir: un récipe que nunca se imprimió no figura y la sección dice «Aún sin QR».
     *
     * @return array{paciente:array<int,string>, pareja:?string, anular:\Closure, reactivar:\Closure, anularPareja:string, reactivarPareja:string}
     */
    private function estadoDeLasVerificaciones(Consulta $consulta): array
    {
        $id = $consulta->id;

        return $this->verificaciones->estados($consulta) + [
            'anular' => fn (int $numero) => route('clinica.consulta.recipes.verificacion.anular', [$id, $numero]),
            'reactivar' => fn (int $numero) => route('clinica.consulta.recipes.verificacion.reactivar', [$id, $numero]),
            'anularPareja' => route('clinica.consulta.recipe-pareja.verificacion.anular', $id),
            'reactivarPareja' => route('clinica.consulta.recipe-pareja.verificacion.reactivar', $id),
        ];
    }

    /**
     * Lo que la sección de récipes ofrece para **imprimir** (WEB-5.2): el diálogo con las opciones guardadas del
     * médico, la próxima cita que saldría al pie de la hoja, "Hacer cita" —el formulario de la agenda con el
     * paciente ya elegido, que vuelve a esta sección— y la vista previa de cada récipe. Las URLs las resuelve
     * el controlador, como en el resto de la web clínica.
     *
     * @return array{dialogo:OpcionesDeImpresionVista, proximaCita:?string, hacerCita:string, imprimir:\Closure, imprimirPareja:string}
     */
    private function impresion(Consulta $consulta, int $pacienteId, Medico $medico): array
    {
        $id = $consulta->id;
        $proxima = $this->proximaCita->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, $pacienteId);

        return [
            'dialogo' => new OpcionesDeImpresionVista($this->membretes->opciones($medico), route('clinica.recipes.opciones')),
            'proximaCita' => $proxima === null ? null : ProximaCitaDelRecipe::texto($proxima),
            'hacerCita' => route('clinica.agenda.nueva', ['paciente_id' => $pacienteId, 'volver_consulta' => $id]),
            'imprimir' => fn (int $numero) => route('clinica.consulta.recipes.imprimir', [$id, $numero]),
            'imprimirPareja' => route('clinica.consulta.recipe-pareja.imprimir', $id),
        ];
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
        // La sección genérica de exámenes (WEB-4.7) muestra **todas** las imágenes de la consulta…
        if ($seccion === ConsultaShellVista::SECCION_EXAMENES) {
            // «Esta consulta | Todas» (WEB-11.22): con `?alcance=todas` se ven las imágenes de todas las consultas del paciente.
            $todas = request()->query('alcance') === 'todas';
            $url = fn (?string $alcance) => route('clinica.consulta', array_filter([
                $consulta->id, 'seccion' => ConsultaShellVista::SECCION_EXAMENES, 'alcance' => $alcance,
            ]));

            return new ImagenesDeLaConsultaVista(
                $todas
                    ? $imagenes->deLaHistoria($regMedico, (int) $consulta->numhistoria)
                    : $imagenes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                route('clinica.consulta.examenes.subir', $consulta->id),
                // Una función por fila: la ruta lleva el id de la imagen y armar la URL concatenando ids cae
                // en otra ruta (trampa 22 del repo, que ya costó una vez en los motivos).
                fn (int $fila) => route('clinica.consulta.examenes.descargar', array_filter([$consulta->id, $fila, 'alcance' => $todas ? 'todas' : null])),
                fn (int $fila) => route('clinica.consulta.examenes.quitar', [$consulta->id, $fila]),
                null,
                [
                    ['texto' => 'Esta consulta', 'url' => $url(null), 'activa' => ! $todas],
                    ['texto' => 'Todas', 'url' => $url('todas'), 'activa' => $todas],
                ],
                (int) $consulta->nroconsulta,
            );
        }

        // …el estudio ecográfico (WEB-11.12) sólo las del **tipo de estudio** que se está mirando…
        if ($seccion === ConsultaShellVista::SECCION_ESTUDIO) {
            $clave = TagDeImagen::deUnEco(TiposDeEstudio::normalizar((string) request()->query('tipo', '')));

            return new ImagenesDeLaConsultaVista(
                $imagenes->deUnExamen($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, $clave),
                route('clinica.consulta.examenes.subir', $consulta->id),
                fn (int $fila) => route('clinica.consulta.examenes.descargar', [$consulta->id, $fila]),
                fn (int $fila) => route('clinica.consulta.examenes.quitar', [$consulta->id, $fila]),
                $clave,
            );
        }

        // …y la solapa de un examen (WEB-6.5) sólo las **suyas**: las que están etiquetadas para ese examen
        // (`TagDeImagen`). Las rutas son las mismas tres —subir, servir y quitar—, con el examen de vuelta.
        $pestana = $seccion === ConsultaShellVista::SECCION_EVALUACION
            ? PestanasDelExamen::normalizar((string) request()->query('pestana', ''))
            : null;

        if ($pestana === null || TagDeImagen::deLaSolapa($pestana) === null) {
            return null;
        }

        return new ImagenesDeLaConsultaVista(
            $imagenes->deUnExamen($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, $pestana),
            route('clinica.consulta.examenes.subir', $consulta->id),
            fn (int $fila) => route('clinica.consulta.examenes.descargar', [$consulta->id, $fila]),
            fn (int $fila) => route('clinica.consulta.examenes.quitar', [$consulta->id, $fila]),
            $pestana,
        );
    }

    /**
     * La tarjeta de los **lienzos del cuello uterino** de la colposcopía (A-13), o `null` si la solapa que se está
     * mirando no es la colposcopía. Las dos variantes se leen de una vez y el ViewModel recibe el estado ya resuelto
     * (la base elegida y si hay trazo), sin consultar la base.
     */
    private function dibujosDeLaColposcopia(string $seccion, string $pestana, Consulta $consulta): ?DibujosDeColposcopiaVista
    {
        if ($seccion !== ConsultaShellVista::SECCION_EVALUACION
            || PestanasDelExamen::normalizar($pestana) !== PestanasDelExamen::COLPOSCOPIA) {
            return null;
        }

        return new DibujosDeColposcopiaVista(
            fn (string $variante) => route('clinica.consulta.colposcopia.ver', [$consulta->id, $variante]),
            fn (string $variante) => route('clinica.consulta.colposcopia.guardar', [$consulta->id, $variante]),
            app(DibujosDeColposcopiaDeLaConsulta::class)->estado($consulta),
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
        $delPaciente = $antecedentes->delPaciente($regMedico, (string) $consulta->numhistoria);
        $tipos = $antecedentes->tipos();
        $tipo = TipoDeAntecedentes::elegido((string) request()->query('tipo', ''), $delPaciente, $tipos);

        return new SeccionDeAntecedentesCatalogoVista(
            // El catálogo del **tipo elegido** (A-06): el chip filtra las dos columnas, así que acá no hay
            // «todos los tipos» ni un `?tipo=` distinto del de los antecedentes del paciente.
            $antecedentes->catalogo($regMedico, $buscar, $tipo),
            $delPaciente->pluck('codigo')->all(),
            $tipos,
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
        );
    }
}
