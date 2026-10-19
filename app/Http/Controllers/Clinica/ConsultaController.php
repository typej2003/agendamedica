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
use App\Clinica\Consulta\SeccionesDeLaConsulta;
use App\Clinica\Consulta\Vistas\TextoDelExamenVista;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\ExamenesDeLaConsulta;
use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\ImagenDelExamen;
use App\Clinica\Consulta\ImagenDelExamenDeLaConsulta;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\LaboratorioDeLaPareja;
use App\Clinica\Consulta\MotivosDeLaConsulta;
use App\Clinica\Consulta\PlantillasDelEstudio;
use App\Clinica\Consulta\PlantillasDelFormato;
use App\Clinica\Consulta\PlantillasDelTexto;
use App\Clinica\Consulta\MapaDelEstudio;
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
use App\Clinica\Consulta\Vistas\ImagenDelExamenVista;
use App\Clinica\Consulta\Vistas\EvaluacionFisicaVista;
use App\Clinica\Consulta\Vistas\EvolucionVista;
use App\Clinica\Consulta\Vistas\HospitalizacionVista;
use App\Clinica\Consulta\Vistas\ImagenesDeLaConsultaVista;
use App\Clinica\Consulta\Vistas\InformeVista;
use App\Clinica\Consulta\Vistas\OrdenDeCatalogoVista;
use App\Clinica\Consulta\Vistas\OrdenVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\MedicionesVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Crecimiento\MedicionesDeLaConsulta;
use App\Clinica\Crecimiento\ReferenciaDeCrecimiento;
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

        // **O-22 (2026-10-17): `?seccion=examenes` ya no es una sección.** La galería de imágenes vive ahora
        // como solapa de *Evaluación física* y los exámenes informados, dentro de *Resultado de exámenes*. Se
        // redirige (302) para no dejar enlaces viejos muertos ni romper el historial del navegador.
        if ((string) $request->query('seccion', '') === ConsultaShellVista::SECCION_EXAMENES) {
            return redirect()->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_EVALUACION,
                'pestana' => PestanasDelExamen::IMAGENES,
            ]);
        }

        // El día de la consulta, ya interpretado una sola vez: es lo que muestra el encabezado y lo que
        // elige la agenda a la que se vuelve.
        $dia = $consulta->fecha ? Carbon::parse($consulta->fecha) : FechaDelConsultorio::hoy();

        // **La consulta abre en Motivos de consulta** (O-06, 2026-10-17): sin `?seccion=` se trata como
        // `motivos` —el ítem 1 del catálogo—, sin redirect (la URL queda limpia) y con el mismo valor para el
        // contenido, el shell y el grupo «Consulta» del menú. Un `?seccion=` que no existe **no** se pisa: cae
        // en el estado vacío del shell, como siempre.
        $seccion = SeccionesDeLaConsulta::deEntrada((string) $request->query('seccion', ''));
        $urlConsulta = route('clinica.consulta', $consulta->id);

        // **Los ítems de la consulta pediátrica que abren otra pantalla** (2026-10-19). En el árbol de PediaReport
        // son ítems propios, pero el dato ya vive en otra pantalla de la web y no se duplica: se redirige (302),
        // como con `?seccion=examenes` (O-22). El menú sigue mostrando el ítem pediátrico.
        $destino = $this->destinoDeLaSeccionPediatrica($seccion, $consulta, $paciente, $contexto);
        if ($destino !== null) {
            return $destino;
        }

        // Las alertas (WEB-11.6): todas las del paciente, fijas en la pantalla; y el diálogo de aviso **una vez por atención** (el id
        // queda en la sesión y `MostradorController` lo olvida al darle «Atender» otra vez).
        // Pediatría (WEB-12.3): sus siete tipos de antecedente (Patológicos, C.A., Madre…) se siembran en el catálogo del médico la primera vez.
        if ($this->alcance()->esPediatria()) {
            app(\App\Actions\Pacientes\SembrarTiposDeAntecedentePediatricos::class)->ejecutar($contexto['reg_medico']);
        }

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

        // El aviso de vacunas pendientes (WEB-7.4.4d): sólo en pediatría y sólo en la consulta (la agenda de la secretaría no lo ve).
        $avisoVacunas = $this->alcance()->esPediatria()
            ? app(\App\Clinica\Vacunas\AvisoDeVacunas::class)->de(
                $contexto['reg_medico'], (int) $paciente->id, \App\Clinica\Pacientes\CamposDePaciente::historiaNumerica($paciente->numhistoria),
                FechaDelConsultorio::hoy()->toDateString(),
            )
            : null;
        // El grupo «Consulta» del menú lateral (revisión de Alexander): el armazón necesita saber qué consulta se está
        // atendiendo y qué sección está abierta para armar sus ítems y marcar el activo. Se comparte acá —y no se lee
        // de la ruta en el compositor— porque el `id` del paciente y la sección ya están resueltos en este método.
        view()->share('consulta_en_curso', [
            'id' => (int) $consulta->id,
            'seccion' => $seccion,
        ]);

        return view('clinica.consulta.ver', [
            'avisoVacunas' => $avisoVacunas === null ? null : $avisoVacunas + ['url' => route('clinica.pacientes.vacunas', $paciente->id)],
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
                $contexto['specialty']?->slug,
                // La pestaña de solicitudes (O-30): el shell decide con ella si el encabezado ofrece el panel
                // con el resumen del paciente (sólo en las pantallas donde se redacta un documento).
                (string) $request->query('solicitud', ''),
                // Las secciones que encienden los postgrados de la especialidad del contexto (2026-10-19).
                $contexto['secciones_postgrados'] ?? [],
            ),
            // La sección activa: una sola. Sin `?seccion=` es Motivos (O-06) y el shell sin ninguna sección
            // válida muestra su estado vacío. Se llama `contenido` y no `seccion` porque `seccion` ya es el
            // nombre del **ViewModel** para los parciales de cada sección (el shell y la sección no pueden
            // llamarse igual).
            'contenido' => $seccion === ConsultaShellVista::SECCION_SOLICITUDES
                ? $this->solicitudes($consulta, $registros, $dia, $paciente, $constancias, $reposos, $referencias, $informes, $doctores, $hospitalizaciones, $dietas, $laboratorio, $estudios, $catalogoDeExamenes, $resumenes)
                : $this->seccion($seccion, $consulta, $contexto['reg_medico'], $motivos, $examen, $antecedentes, $diagnosticos, $evolucion, $examenes, $imagenes, $recipes, $pareja, $dia, (int) $paciente->id, $contexto['medico'], $eco, $ecoObstetrico, $ecoObstetricoTercero, $ecoDoppler, $ecoFetal, $furPrenatal, $gestas, $informePrenatal, $ecoMamario, $ecoAbdominal, $doctores),
            // Las tarjetas que acompañan a una sección y no son parte de su contenido: el buscador del
            // catálogo de antecedentes (WEB-4.4) y la subida de imágenes de los exámenes (WEB-4.7). Las dos
            // son herramientas de la sección —una agrega antecedentes, la otra sube un archivo—, no su
            // cuerpo, y por eso viajan aparte.
            'catalogo' => $this->catalogoDeAntecedentes($seccion, $consulta, $contexto['reg_medico'], $antecedentes),
            'imagenes' => $this->imagenesDeLaConsulta($seccion, $consulta, $contexto['reg_medico'], $imagenes),
            // La tarjeta de **la imagen del examen** (O-09: el radiobutón entre la imagen predeterminada y el
            // videocolposcopio, con sus lienzos y sus fotos). `null` en las solapas que no la tienen.
            'imagen' => $this->imagenDelExamen($seccion, (string) request()->query('pestana', ''), $consulta, $contexto['reg_medico'], $imagenes),
        ]);
    }

    /**
     * La solapa *Mediciones* (WEB-7.4.3a): la fila de la consulta, el niño (sexo y nacimiento) y la referencia de crecimiento del consultorio.
     * Sin referencia cargada, el ViewModel lo dice en vez de fallar.
     */
    private function vistaDeMediciones(Consulta $consulta, string $regMedico, int $pacienteId, string $urlVolver): MedicionesVista
    {
        $paciente = \App\Models\Paciente::find($pacienteId);

        return new MedicionesVista(
            (new MedicionesDeLaConsulta())->de($consulta),
            $paciente?->fnacimiento ? (string) \Illuminate\Support\Carbon::parse($paciente->fnacimiento)->toDateString() : null,
            $paciente?->sexo,
            $consulta->fecha?->toDateString(),
            (new ReferenciaDeCrecimiento())->cargar($regMedico),
            route('clinica.consulta.mediciones.guardar', $consulta->id),
            $urlVolver,
            (array) old(),
            route('clinica.pacientes.crecimiento', $pacienteId),
        );
    }

    /** Qué partes de la consulta aplican a la especialidad del contexto (WEB-12.1). */
    private function alcance(): AlcanceDeLaEspecialidad
    {
        return AlcanceDeLaEspecialidad::de(request()->attributes->get('contexto_clinico')['specialty']?->slug);
    }

    /**
     * **Las secciones de la consulta pediátrica que abren otra pantalla** (2026-10-19).
     *
     * En el árbol de PediaReport son ítems propios, pero en la web el dato ya vive en una pantalla y no se
     * duplica: *Exámenes/Estudios* es la solapa *Resultado de exámenes*, *Inmunizaciones* el carnet de vacunas
     * del paciente, *Alimentación* la dieta / recomendaciones de la consulta y *Representante* la ficha del
     * paciente. Devuelve `null` para cualquier otra sección (incluida la pre-factura, que todavía no tiene
     * pantalla: cae en el estado vacío del shell como cualquier ítem sin pantalla).
     */
    private function destinoDeLaSeccionPediatrica(string $seccion, Consulta $consulta, object $paciente, array $contexto)
    {
        // Sólo si la sección **existe en el catálogo de esta especialidad**: en ginecología esas claves no están
        // y tienen que caer en el estado vacío del shell, no redirigir a una pantalla que no es de su consulta.
        if (! SeccionesDeLaConsulta::existeSeccion(
            $seccion,
            $contexto['specialty']?->slug,
            $contexto['secciones_postgrados'] ?? [],
        )) {
            return null;
        }

        return match ($seccion) {
            SeccionesDeLaConsulta::SECCION_EXAMENES_ESTUDIOS => redirect()->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_EVALUACION,
                'pestana' => TextosDelExamen::RESULTADOS,
            ]),
            SeccionesDeLaConsulta::SECCION_INMUNIZACIONES => redirect()->route('clinica.pacientes.vacunas', $paciente->id),
            SeccionesDeLaConsulta::SECCION_ALIMENTACION => redirect()->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
                'solicitud' => PestanasDeSolicitudes::DIETA,
            ]),
            SeccionesDeLaConsulta::SECCION_REPRESENTANTE => redirect()->route('clinica.pacientes.ver', $paciente->id),
            default => null,
        };
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
        ImagenesDeLaConsulta $imagenes,
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
            $pestana = $this->alcance()->pestanaDelExamen((string) request()->query('pestana', ''));

            $url = fn (string $clave) => route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_EVALUACION,
                'pestana' => $clave,
            ]);

            // La **galería de imágenes de los estudios** (O-22): una solapa más, al final, que no es de texto.
            // Es la que vivía en la sección *Exámenes* retirada: «Esta consulta / Todas» y la subida, con el
            // parcial `_imagenes` de siempre (la columna `observacion` sigue teniendo dos dueños, trampa 116).
            if ($pestana === PestanasDelExamen::IMAGENES) {
                return new SeccionDeEvaluacionVista(
                    $pestana,
                    new PestanasDelExamen($url, $this->alcance()->pestanasDelExamenOcultas()),
                    $this->galeria($consulta, $regMedico, $imagenes),
                );
            }

            // Las mediciones de pediatría (WEB-7.4.3a): su propia tabla (`percentiles_paciente`) y su propio ViewModel.
            if ($pestana === PestanasDelExamen::MEDICIONES) {
                return new SeccionDeEvaluacionVista(
                    $pestana,
                    new PestanasDelExamen($url, $this->alcance()->pestanasDelExamenOcultas()),
                    $this->vistaDeMediciones($consulta, $regMedico, $pacienteId, $url($pestana)),
                );
            }

            // El examen físico y el cuestionario de pediatría (WEB-12.2): sus propias tablas (`examen_fisico` y `cuestionario`) y sus ViewModels.
            if ($pestana === PestanasDelExamen::EXAMEN || $pestana === PestanasDelExamen::CUESTIONARIO) {
                $pediatrico = new \App\Clinica\Consulta\ExamenPediatrico();

                return new SeccionDeEvaluacionVista(
                    $pestana,
                    new PestanasDelExamen($url, $this->alcance()->pestanasDelExamenOcultas()),
                    $pestana === PestanasDelExamen::EXAMEN
                        ? new \App\Clinica\Consulta\Vistas\ExamenPediatricoVista(
                            $pediatrico->examen($consulta),
                            route('clinica.consulta.examen-pediatrico.guardar', $consulta->id),
                            route('clinica.consulta.examen-pediatrico.resumen', $consulta->id),
                            $url($pestana),
                            PlantillasDelTexto::de($regMedico, 'RF')->all(),
                            (array) old(),
                        )
                        : new \App\Clinica\Consulta\Vistas\CuestionarioVista(
                            $pediatrico->cuestionario($consulta),
                            route('clinica.consulta.cuestionario.guardar', $consulta->id),
                            $url($pestana),
                            (array) old(),
                        ),
                );
            }

            // La lectura de las solapas de texto comparte la lectura del examen (`examen_fisico_nuevo`) con la
            // evaluación física: la copia de la colposcopía es esa misma fila.
            $texto = new TextoDelExamen($examen);

            // La hoja de la solapa, si tiene (la colposcopía y la histeroscopía; la citología y el resultado de
            // exámenes no tienen informe en el escritorio).
            $documento = TextosDelExamen::documento($pestana);

            // **Los exámenes informados por el laboratorio** (O-22): la lista de sólo lectura que estaba en
            // la sección *Exámenes* retirada se muestra dentro de la solapa *Resultado de exámenes*, junto a
            // la nota que escribe el médico. La fila se llavea por `nrohistoria` + `nroconsulta`
            // (`examen_paciente`, el `dw_12` de la pestaña de laboratorio del escritorio) y su observación de
            // conjunto es `examen_obs.observacion`.
            $informados = $pestana === TextosDelExamen::RESULTADOS
                ? new SeccionDeExamenesVista(
                    $examenes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                    $examenes->observacion($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
                    $examenes->tieneCatalogo($regMedico),
                    // *Solicitudes → Laboratorio* (WEB-5.4): donde se piden y se corrigen esos mismos exámenes.
                    route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::LABORATORIO]),
                )
                : null;

            return new SeccionDeEvaluacionVista(
                $pestana,
                new PestanasDelExamen($url, $this->alcance()->pestanasDelExamenOcultas()),
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
                        // O-22: la lista de exámenes informados sólo en *Resultado de exámenes*.
                        $informados,
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
            $tipo = $this->alcance()->tipoDeEstudio((string) request()->query('tipo', ''));

            // El eco abdominal no se ofrece en ginecología: el botón está oculto en el escritorio (WEB-11.14, Alexander). En pediatría es
            // el único (WEB-12.1).
            $tipos = new TiposDeEstudio(fn (string $clave) => route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => $clave,
            ]), $this->alcance()->tiposDeEstudioOcultos());

            // Las plantillas de texto del estudio (revisión de Alexander, 2026-10-17): el tipo de `formato_print`
            // que el escritorio usa en esa ventana y la columna del informe a la que se copian. El
            // ecocardiograma fetal no tiene plantillas en el escritorio, así que `de()` devuelve `null` y ese
            // estudio no ofrece el botón (ver `PlantillasDelEstudio`).
            $plantillas = PlantillasDelEstudio::de($regMedico, $tipo);

            // Las plantillas del **formato** del estudio (O-17): los campos descriptivos del estudio
            // guardados con un título, para completar los vacíos de la próxima paciente. Viaja con la
            // sección porque el panel se pinta arriba del formulario y la lista es la del estudio activo.
            $formato = $this->formatoDelEstudio($consulta, $tipo);

            // O-13: la fecha con la que abre un estudio que todavía no tiene fecha guardada: **hoy del
            // consultorio** (`FechaDelConsultorio::hoy()`, nunca `now()`: pasadas las 20:00 de Caracas son
            // días distintos, trampa 59). Los ViewModels no consultan el reloj: se la pasan.
            $hoyDelEstudio = FechaDelConsultorio::hoy()->toDateString();

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
                        $hoyDelEstudio,
                    ),
                    $plantillas,
                    $formato,
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
                        $hoyDelEstudio,
                    ),
                    $plantillas,
                    $formato,
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
                        // O-13: la fecha del estudio abre con hoy aunque la fila todavía no exista (la
                        // precarga del escritorio trae los dos textos, no la fecha).
                        $hoyDelEstudio,
                    ),
                    $plantillas,
                    $formato,
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
                        $hoyDelEstudio,
                    ),
                    $plantillas,
                    $formato,
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
                        $hoyDelEstudio,
                    ),
                    $plantillas,
                    $formato,
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
                        $hoyDelEstudio,
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
                    $hoyDelEstudio,
                ),
                $plantillas,
                $formato,
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
            // cuando se está creando una **gesta** nueva (`&nueva=1`).
            //
            // `nueva` es **sólo de la pestaña *Prenatal***: en las pestañas por fecha (*Laboratorio*,
            // *Controles* y *Reporte*) una fecha nueva no lleva marca —se reconoce por la **ausencia de
            // `&fecha=`** (`FechasDelEmbarazoVista::esNueva()`)—. Ignorarla ahí además blinda el enlace: un
            // `?nueva=1` viejo en *Laboratorio* anulaba la gesta elegida y caía en `siguienteClave()`, una
            // gesta que todavía no existe; el formulario en blanco viajaba con ese número y la fila se
            // guardaba huérfana, fuera de la vista del médico.
            $pedida = (int) request()->query('gesta', 0);
            $creandoGesta = $pestana === PestanasDelEmbarazo::PRENATAL && request()->boolean('nueva');
            $elegida = $creandoGesta
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
                    // «Agregar una fecha»: la misma pestaña con la gesta en curso y **sin `fecha`** —y sin
                    // `nueva`, que es la marca de la gesta nueva de *Prenatal*—. Así el formulario se abre
                    // en blanco (la fecha de hoy y los campos vacíos) pero **en esta misma gesta**: antes la
                    // marca `nueva` hacía que la pantalla saltara a la gesta siguiente, que no existe.
                    urlNueva: $url(['gesta' => $claveDeLaGesta]),
                    urlFecha: fn (string $fecha) => $url(['gesta' => $claveDeLaGesta, 'fecha' => $fecha]),
                    // Una línea de ayuda como máximo en el cuerpo (O-23): lo que era un párrafo con la lista
                    // de lo que se carga en cada pestaña ya lo dice el rótulo de sus campos.
                    ayuda: $esLaboratorio
                        ? 'Cada fecha es un laboratorio de esta gesta; se guarda con el botón.'
                        : 'Cada fecha es un control de esta gesta; se guarda con el botón.',
                    aviso: 'Hay cambios sin guardar. Si sales ahora, se pierden.',
                    old: (array) old(),
                    // O-13: un laboratorio o un control **nuevo** abre con la fecha de hoy del consultorio
                    // (una fila que ya existe manda con su fecha, y la FUR nunca se toca).
                    fechaPorDefecto: FechaDelConsultorio::hoy()->toDateString(),
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
            // Con el nombre que cada médico les da (WEB-12.3: las siete categorías de pediatría; ginecología no cambia).
            $tipos = $antecedentes->tipos($regMedico);

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
                // El "Quitar" de cada fila (O-07): un POST inmediato, con el código en el cuerpo.
                route('clinica.consulta.antecedentes.quitar', $consulta->id),
            );
        }

        if ($seccion === ConsultaShellVista::SECCION_DIAGNOSTICO) {
            $buscar = trim((string) request()->query('buscar', ''));
            $pagina = $diagnosticos->pagina(
                $regMedico, $buscar, (int) request()->query('pagina_catalogo', 1),
                route('clinica.consulta', [$consulta->id]),
                ['seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO] + ($buscar !== '' ? ['buscar' => $buscar] : []),
            );

            return new SeccionDeDiagnosticosVista(
                // Los diagnósticos de **esta** consulta: la fila se llavea por `nrohistoria` +
                // `nroconsulta` + `codediagnostico` (`DiagnosticosDeLaConsulta`).
                $diagnosticos->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta),
                $pagina->getCollection(),
                $buscar,
                route('clinica.consulta.diagnosticos.guardar', $consulta->id),
                // La base del buscador, del "Cancelar" y de la vuelta: esta misma sección.
                route('clinica.consulta', [$consulta->id]),
                route('clinica.consulta.diagnosticos.crear', $consulta->id),
                (array) old(),
                $pagina,
                // O-27: las dos escrituras inmediatas.
                route('clinica.consulta.diagnosticos.agregar', $consulta->id),
                route('clinica.consulta.diagnosticos.quitar', $consulta->id),
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
            // En pediatría la pestaña de la dieta es *Alimentación* (WEB-12.4): la misma fila de `dieta_paciente`, con el catálogo de `dietas` además de las plantillas.
            $this->alcance()->esPediatria() ? 'Alimentación' : null,
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
                // O-13: la fecha de inicio del reposo abre con **hoy** (la del consultorio): es la fecha del
                // documento, no la de la consulta que se está editando. Se puede cambiar (un reposo que empieza
                // mañana) y una fecha guardada manda.
                FechaDelConsultorio::hoy()->toDateString(),
                ['guardar' => route('clinica.consulta.reposo.guardar', $id), 'eliminar' => route('clinica.consulta.reposo.eliminar', $id), 'imprimir' => $imprimir('reposo')],
                $old,
            );
        } elseif ($activa === PestanasDeSolicitudes::REFERENCIAS) {
            $buscar = trim((string) request()->query('buscar', ''));

            $vista = new ReferenciasVista(
                $referencias->deLaConsulta($consulta, $registros),
                // O-29: la libreta de colegas **a la vista**, paginada y con su buscador, como el catálogo de motivos.
                $doctores->pagina(
                    $registros,
                    $buscar,
                    (int) request()->query('pagina_catalogo', 1),
                    route('clinica.consulta', [$id]),
                    ['seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::REFERENCIAS]
                        + ($buscar !== '' ? ['buscar' => $buscar] : []),
                ),
                $buscar,
                route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::REFERENCIAS]),
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
                $this->alcance()->esPediatria()
                    ? $dietas->plantillas((string) $consulta->reg_medico)->concat(
                        (new \App\Clinica\Consulta\DietasDelCatalogo())->de((string) $consulta->reg_medico),
                    )->unique('titulo')->sortBy('titulo')->values()
                    : $dietas->plantillas((string) $consulta->reg_medico),
                ['guardar' => route('clinica.consulta.dieta.guardar', $id), 'eliminar' => route('clinica.consulta.dieta.eliminar', $id), 'imprimir' => $imprimir('dieta')],
                $old,
                $this->alcance()->esPediatria() ? 'Alimentación' : 'Dieta / Recomendaciones',
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
     * La pestaña **Laboratorio** (WEB-5.4) como Motivos de consulta (O-29): la orden de exámenes de la consulta —cada fila con su baja
     * inmediata— el catálogo del médico **siempre a la vista** con su «Pasar» por fila, y las rutinas (los tipos de examen con exámenes).
     *
     * @param  array<string,mixed>  $old
     */
    private function laboratorio(Consulta $consulta, LaboratorioDeLaConsulta $laboratorio, CatalogoParaPedirExamenes $catalogo, array $old, string $urlImprimir): OrdenDeCatalogoVista
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

        $orden = OrdenVista::laboratorio(
            $items,
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
                'pareja' => $this->alcance()->muestraLaPareja()
                    ? route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::PAREJA])
                    : '',
            ],
        );

        return $this->ordenDeCatalogo($consulta, $orden, $catalogo, false, [
            'agregar' => route('clinica.consulta.laboratorio.agregar', $id),
            'quitar' => route('clinica.consulta.laboratorio.quitar', $id),
            'resumen' => route('clinica.consulta.laboratorio.guardar', $id),
            'rutina' => route('clinica.consulta.laboratorio.rutinas.agregar', [$id, '__TIPO__']),
            'administrar' => route('clinica.catalogos.examenes'),
        ]);
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
    private function estudios(Consulta $consulta, EstudiosDeLaConsulta $estudios, CatalogoParaPedirExamenes $catalogo, array $old, string $urlImprimir): OrdenDeCatalogoVista
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

        $orden = OrdenVista::estudios(
            $items,
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

        return $this->ordenDeCatalogo($consulta, $orden, $catalogo, true, [
            'agregar' => route('clinica.consulta.estudios.agregar', $id),
            'quitar' => route('clinica.consulta.estudios.quitar', $id),
            'resumen' => route('clinica.consulta.estudios.guardar', $id),
            'rutina' => '',
            'administrar' => route('clinica.catalogos.examenes'),
        ]);
    }

    /**
     * **El catálogo a la vista de la orden** (O-29): la página del catálogo que se está mirando (`?buscar=` y `?tipo=`), el filtro y las
     * rutinas, con las URLs de las escrituras inmediatas.
     *
     * El paginador reusa la ruta de la consulta con `seccion` y `solicitud`, como el catálogo de motivos y diagnósticos.
     *
     * @param  array<string,string>  $urls
     */
    private function ordenDeCatalogo(Consulta $consulta, OrdenVista $orden, CatalogoParaPedirExamenes $catalogo, bool $estudios, array $urls): OrdenDeCatalogoVista
    {
        $id = $consulta->id;
        $regMedico = (string) $consulta->reg_medico;
        $solicitud = $estudios ? PestanasDeSolicitudes::ESTUDIOS : PestanasDeSolicitudes::LABORATORIO;

        $buscar = trim((string) request()->query('buscar', ''));
        // El filtro por tipo es del laboratorio: los estudios son todos del mismo tipo (`002`).
        $tipo = $estudios ? '' : trim((string) request()->query('tipo', ''));

        $pagina = $catalogo->pagina(
            $regMedico,
            $estudios,
            $buscar,
            $tipo,
            (int) request()->query('pagina_catalogo', 1),
            route('clinica.consulta', [$id]),
            ['seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $solicitud]
                + ($buscar !== '' ? ['buscar' => $buscar] : [])
                + ($tipo !== '' ? ['tipo' => $tipo] : []),
        );

        return new OrdenDeCatalogoVista(
            $orden,
            $pagina->getCollection()->all(),
            $buscar,
            $tipo,
            $catalogo->tiposDeLaLista($regMedico, $estudios),
            $catalogo->rutinas($regMedico),
            $urls + ['seccion' => route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $solicitud])],
            // La baja es un `DELETE` con el código en el cuerpo: la misma URL para todas las filas.
            fn (string $codigo) => $urls['quitar'],
            $catalogo->hay($regMedico, $estudios),
            $pagina,
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
     * El ViewModel de los **récipes** de la consulta (WEB-5.1).
     *
     * **La puerta de entrada es el editor** (O-28): sin `?recipe=` se abre el editor del récipe que
     * corresponde —el 1 si la consulta todavía no tiene ninguno, que es el editor de un récipe **nuevo** y
     * no crea ninguna fila— y arriba quedan las pestañas de los que hay más «+ Nuevo». La lista —con
     * «Imprimir», «Editar» y «Eliminar»— sigue en `?seccion=recipes&lista=1`.
     *
     * `?recipe=nuevo` sigue abriendo el editor vacío (es a donde lleva «+ Nuevo») y `?recipe=2` el de un
     * récipe existente; el `?recipe=pareja` (WEB-5.1b) es la segunda pestaña de la sección. Un `?recipe=`
     * que no es `nuevo`, `pareja` ni el número de un récipe de **esta** consulta da **404**: no hay nada
     * que editar y no se confirma qué récipes existen en otra consulta.
     */
    private function recipes(Consulta $consulta, string $regMedico, RecipesDeLaConsulta $recipes, RecipeDeLaPareja $pareja, Carbon $dia, int $pacienteId, Medico $medico): object
    {
        $editando = request()->query('recipe');
        $editando = is_string($editando) && $editando !== '' ? $editando : null;

        $id = $consulta->id;

        // La lista completa de los récipes de la consulta: la usan las pestañas (O-28) y el editor.
        $deLaConsulta = $recipes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta);

        // Sin `recipe` se entra al editor (O-28): el del primer récipe que exista o, si no hay ninguno, el de
        // uno nuevo —que no crea la fila hasta que se guarda—. `?lista=1` es la lista de siempre.
        $verLista = filter_var(request()->query('lista', false), FILTER_VALIDATE_BOOLEAN);
        if ($editando === null && ! $verLista) {
            $primero = $deLaConsulta->sortBy(fn (object $recipe) => (int) $recipe->numero)->first();
            $editando = $primero === null ? 'nuevo' : (string) (int) $primero->numero;
        }

        $urlLista = route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'lista' => 1]);
        $pestanas = new PestanasDeRecipes(
            route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]),
            $this->alcance()->muestraLaPareja()
                ? route('clinica.consulta', [$id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'recipe' => ConsultaShellVista::RECIPE_PAREJA])
                : '',
        );

        if ($editando === ConsultaShellVista::RECIPE_PAREJA && $this->alcance()->muestraLaPareja()) {
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
            $deLaConsulta,
            $editando === null ? null : ($editando === 'nuevo' ? 'nuevo' : (string) (int) $editando),
            // O-13: la fecha de emisión de un récipe **nuevo** abre con hoy del consultorio. Es la fecha del
            // documento —no la de la consulta que se edita— y una fecha guardada manda (el editor de un récipe
            // existente usa la suya).
            FechaDelConsultorio::hoy()->toDateString(),
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
     * **La galería de imágenes de los estudios** (O-22): todas las imágenes de la consulta —o las de toda la
     * historia con `?alcance=todas`— con su subida, el parcial `_imagenes` de siempre.
     *
     * Era la mitad de la sección *Exámenes* (ítem 7, retirado) y ahora es una solapa de *Evaluación física*.
     * No se le pide nada nuevo a la tabla: `imagen_consulta.observacion` sigue teniendo dos dueños (trampa
     * 116) y la fila del escritorio se sigue leyendo como se leía.
     */
    private function galeria(Consulta $consulta, string $regMedico, ImagenesDeLaConsulta $imagenes): ImagenesDeLaConsultaVista
    {
        $todas = request()->query('alcance') === 'todas';

        $url = fn (?string $alcance) => route('clinica.consulta', array_filter([
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => PestanasDelExamen::IMAGENES,
            'alcance' => $alcance,
        ]));

        return new ImagenesDeLaConsultaVista(
            $todas
                ? $imagenes->deLaHistoria($regMedico, (int) $consulta->numhistoria)
                : $imagenes->deLaConsulta($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta),
            route('clinica.consulta.examenes.subir', $consulta->id),
            // Una función por fila: la ruta lleva el id de la imagen y armar la URL concatenando ids cae en
            // otra ruta (trampa 22 del repo, que ya costó una vez en los motivos).
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

    /**
     * El ViewModel de la **subida de imágenes** de la consulta (WEB-4.7), o `null` si la sección activa no
     * ofrece la galería.
     *
     * La galería de la consulta (O-22) vive en la solapa *Imágenes* de *Evaluación física* y la arma
     * `seccion()`; acá queda lo que cuelga de las **otras** solapas —las imágenes de un examen y las de un
     * estudio ecográfico—, que es el mismo parcial con la etiqueta de su examen (`TagDeImagen`). El archivo va
     * al disco privado `clinica` y se sirve por la ruta autorizada de `ConsultaImagenesController`.
     */
    private function imagenesDeLaConsulta(
        string $seccion,
        Consulta $consulta,
        string $regMedico,
        ImagenesDeLaConsulta $imagenes,
    ): ?object {
        // El estudio ecográfico (WEB-11.12) sólo las del **tipo de estudio** que se está mirando…
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

        // …y la solapa de un examen (WEB-6.5) ya no arma esta tarjeta: desde O-09 sus fotos viven adentro de la tarjeta
        // de la imagen del examen (`imagenDelExamen()`), que es la que pide el radiobutón y los lienzos.
        return null;
    }

    /**
     * **La tarjeta de la imagen del examen** (O-09), o `null` si la solapa que se está mirando no la tiene. La lectura la
     * hace `ImagenDelExamenDeLaConsulta` —el modo elegido, la foto que se marca, los trazos— y acá sólo se arman las URLs
     * y se le pasan a la vista, que no consulta nada.
     *
     * Las fotos del examen son las de siempre (`ImagenesDeLaConsultaVista`, con la etiqueta de `TagDeImagen`): la tarjeta
     * las muestra en el modo del videocolposcopio, con la lista y la subida adentro.
     */
    private function imagenDelExamen(
        string $seccion,
        string $pestana,
        Consulta $consulta,
        string $regMedico,
        ImagenesDeLaConsulta $imagenes,
    ): ?ImagenDelExamenVista {
        if ($seccion !== ConsultaShellVista::SECCION_EVALUACION) {
            return null;
        }

        $examen = ImagenDelExamen::deLaSolapa(PestanasDelExamen::normalizar($pestana));

        if ($examen === null) {
            return null;
        }

        $estado = app(ImagenDelExamenDeLaConsulta::class)->de($consulta, $examen);

        return new ImagenDelExamenVista(
            $examen,
            $estado,
            fn (string $variante, string $verbo) => route('clinica.consulta.examen.imagen.' . $verbo, [$consulta->id, $examen, $variante]),
            fn (int $foto, string $verbo) => route('clinica.consulta.examen.imagen.' . $verbo, [$consulta->id, $examen, 'foto-' . $foto]),
            fn (int $foto) => route('clinica.consulta.examenes.descargar', [$consulta->id, $foto]),
            route('clinica.consulta.examen.imagen.elegir', [$consulta->id, $examen]),
            new ImagenesDeLaConsultaVista(
                $imagenes->deUnExamen($regMedico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, $examen),
                route('clinica.consulta.examenes.subir', $consulta->id),
                fn (int $fila) => route('clinica.consulta.examenes.descargar', [$consulta->id, $fila]),
                fn (int $fila) => route('clinica.consulta.examenes.quitar', [$consulta->id, $fila]),
                $examen,
                [],
                null,
                // El aviso de la baja (O-09, regla 5): si la foto tiene marcas, se van con ella.
                fn (int $fila) => isset($estado->trazos[$fila])
                    ? '¿Quitar esta foto? Tiene marcas del médico: se borran con ella y no se pueden deshacer.'
                    : '¿Quitar esta foto del estudio? Se borra el archivo del servidor y no se puede deshacer.',
            ),
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
        $tipos = $antecedentes->tipos($regMedico);
        $tipo = TipoDeAntecedentes::elegido((string) request()->query('tipo', ''), $delPaciente, $tipos);

        return new SeccionDeAntecedentesCatalogoVista(
            // El catálogo del **tipo elegido** (A-06): el chip filtra las dos columnas, así que acá no hay
            // «todos los tipos» ni un `?tipo=` distinto del de los antecedentes del paciente.
            $antecedentes->catalogo($regMedico, $buscar, $tipo),
            $delPaciente->pluck('codigo')->all(),
            $tipos,
            // «Agregar» es un `POST` inmediato (O-07); el código va en el cuerpo del formulario de la fila.
            route('clinica.consulta.antecedentes.agregar', $consulta->id),
            route('clinica.consulta.antecedentes.crear', $consulta->id),
            $buscar,
            $tipo,
        );
    }

    /**
     * **Las plantillas del formato del estudio** (O-17), listas para el panel: las del tipo activo, los
     * campos descriptivos que se guardan y la frase que dice qué hace.
     *
     * La lectura está en `PlantillasDelFormato`; acá sólo se arma lo que la vista necesita. `null` si el
     * tipo no es de los que admiten plantillas del formato (hoy, todos los del ítem 5).
     *
     * @return array{plantillas:array<int,array{titulo:string, valores:array<string,string>}>, campos:array<int,string>, bloques:array<int,string>, queHace:string, urlGuardar:string, tipo:string}|null
     */
    private function formatoDelEstudio(Consulta $consulta, string $tipo): ?array
    {
        if (! PlantillasDelFormato::admite($tipo)) {
            return null;
        }

        $plantillas = PlantillasDelFormato::de((string) $consulta->reg_medico, $tipo)
            ->map(fn (array $plantilla) => [
                'titulo' => $plantilla['titulo'],
                'valores' => $plantilla['valores'],
            ])
            ->values()
            ->all();

        return [
            'plantillas' => $plantillas,
            'campos' => MapaDelEstudio::columnasDescriptivas($tipo),
            'bloques' => PlantillasDelFormato::bloquesQueToca($tipo),
            'queHace' => PlantillasDelFormato::queHace(),
            'urlGuardar' => route('clinica.consulta.estudio.plantilla.guardar', [$consulta->id, 'tipo' => $tipo]),
            'tipo' => $tipo,
        ];
    }
}
