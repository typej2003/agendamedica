<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\CitaDeLaConsulta;
use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\EvolucionDeLaConsulta;
use App\Clinica\Consulta\FurDelControlPrenatal;
use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\ReferenciasDeLaConsulta;
use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\ExamenesDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\RecipeDeLaPareja;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\ConstanciaVista;
use App\Clinica\Consulta\Vistas\DietaVista;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\Vistas\EcoGinecologicoVista;
use App\Clinica\Consulta\Vistas\EcoObstetricoVista;
use App\Clinica\Consulta\Vistas\EcoObstetricoTerceroVista;
use App\Clinica\Consulta\Vistas\EnfermedadActualVista;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use App\Clinica\Consulta\Vistas\EvolucionVista;
use App\Clinica\Consulta\Vistas\HospitalizacionVista;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use App\Clinica\Consulta\Vistas\InformeVista;
use App\Clinica\Consulta\Vistas\OrdenVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\ReferenciasVista;
use App\Clinica\Consulta\Vistas\ReposoVista;
use App\Clinica\Consulta\Vistas\SeccionDeSolicitudesVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesCatalogoVista;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Consulta\Vistas\SeccionDeDiagnosticosVista;
use App\Clinica\Consulta\Vistas\SeccionDeEstudioVista;
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
        FurDelControlPrenatal $furPrenatal,
        GestasDeLaHistoria $gestas,
        InformePrenatal $informePrenatal,
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
            'contenido' => $seccion === ConsultaShellVista::SECCION_SOLICITUDES
                ? $this->solicitudes($consulta, $registros, $dia, $paciente, $constancias, $reposos, $referencias, $informes, $doctores, $hospitalizaciones, $dietas, $laboratorio, $estudios, $catalogoDeExamenes)
                : $this->seccion($seccion, $consulta, $contexto['reg_medico'], $motivos, $examen, $antecedentes, $diagnosticos, $evolucion, $examenes, $recipes, $pareja, $dia, (int) $paciente->id, $contexto['medico'], $eco, $ecoObstetrico, $ecoObstetricoTercero, $furPrenatal, $gestas, $informePrenatal),
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
        RecipesDeLaConsulta $recipes,
        RecipeDeLaPareja $pareja,
        Carbon $dia,
        int $pacienteId,
        Medico $medico,
        EcoGinecologico $eco,
        EcoObstetrico $ecoObstetrico,
        EcoObstetricoTercero $ecoObstetricoTercero,
        FurDelControlPrenatal $furPrenatal,
        GestasDeLaHistoria $gestas,
        InformePrenatal $informePrenatal,
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

        if ($seccion === ConsultaShellVista::SECCION_ESTUDIO) {
            // El tipo del estudio viaja en la URL (`&tipo=`): el contenedor muestra los que existen hoy
            // (el ginecológico, WEB-6.1) y los demás llegan con las tareas siguientes de F6.
            $tipo = TiposDeEstudio::normalizar((string) request()->query('tipo', ''));

            // El del 2.º/3.er trimestre es el mismo contenedor con su propio ViewModel (su mapa tiene 55
            // columnas en seis bloques, contra las 38 del primero).
            if ($tipo === TiposDeEstudio::TERCERO) {
                return new SeccionDeEstudioVista(
                    $tipo,
                    new TiposDeEstudio(fn (string $clave) => route('clinica.consulta', [
                        $consulta->id,
                        'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                        'tipo' => $clave,
                    ])),
                    new EcoObstetricoTerceroVista(
                        $ecoObstetricoTercero->de($consulta),
                        route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]),
                        route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]),
                        // La hoja del 2.º/3.er trimestre llega con su paso: hasta entonces no se ofrece.
                        '',
                        null,
                        null,
                        (array) old(),
                    ),
                );
            }

            return new SeccionDeEstudioVista(
                $tipo,
                new TiposDeEstudio(fn (string $clave) => route('clinica.consulta', [
                    $consulta->id,
                    'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                    'tipo' => $clave,
                ])),
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
    ): SeccionDeSolicitudesVista {
        $id = $consulta->id;
        $activa = PestanasDeSolicitudes::normalizar(request()->query('solicitud'));
        $old = (array) old();

        $pestanas = new PestanasDeSolicitudes(
            fn (string $clave) => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $clave]),
        );

        // La hoja impresa de cada documento: la misma página que imprime la secretaría desde la cita.
        $imprimir = fn (string $clave) => route('clinica.consulta.documentos.imprimir', [$id, 'documento' => $clave]);

        if ($activa === PestanasDeSolicitudes::LABORATORIO) {
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
                ['guardar' => route('clinica.consulta.informe.guardar', $id), 'eliminar' => route('clinica.consulta.informe.eliminar', $id), 'imprimir' => $imprimir('informe')],
                $old,
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
