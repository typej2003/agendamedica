<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Clinica\Impresion\MedicoQueFirma;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Impresion\QrParaLosRecipes;
use App\Clinica\Impresion\Vistas\DocumentosDeLaCitaVista;
use App\Clinica\Impresion\Vistas\HojasImpresasVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Clinica\Impresion\Vistas\SinDocumentoVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * **Imprimir los documentos de una consulta** (WEB-5.3a): la constancia, el reposo, las referencias, el informe y —cuando se
 * piden juntos— los récipes (WEB-5.2). La página de impresión es la del navegador (PLAN-WEB.md R6, decisión 17): media hoja por
 * documento, con el membrete, la firma y el pie del formato del médico.
 *
 * Hay **dos caminos a las mismas hojas**, y los dos terminan en `vistaPrevia()`:
 *
 *  - **El médico**, desde la pestaña de cada documento o desde la historia del paciente
 *    (`/clinica/consulta/{consulta}/documentos/imprimir?documento=reposo`). Va en el grupo `role:Root|Medico` de la consulta: la
 *    secretaría recibe 403.
 *  - **La secretaría** —y también el médico—, desde la fila de una cita **ya atendida** de la agenda
 *    (`/clinica/agenda/{cola}/documentos` y `/imprimir`), que **reemplaza** al «Imprimir récipe» de WEB-5.2 y lista **todo lo
 *    imprimible de esa consulta**. La secretaría **no entra a la consulta** ni a la ficha clínica, y estas rutas no se la abren:
 *    sólo **imprime**. Lo único clínico que ve es lo que sale en cada hoja y la lista de qué documentos tiene la cita.
 *
 * Qué ve cada uno: el médico puede imprimir la constancia siempre (su texto base existe para toda consulta); la secretaría la
 * ve en la lista sólo si el médico escribió algo propio (`QueEsUnDocumento::constancia`). Los demás documentos son documentos
 * de verdad para los dos o no existen.
 *
 * Todo se resuelve contra el **contexto**: una consulta o una cita de otro consultorio responde 404 sin confirmar que existe, y
 * el membrete es el del formato del consultorio. Desde la Fase 5 del cambio de `reg_medico` ese formato es el del **centro**
 * cuando él lo eligió (`formato_reportes = 'centro'`), y **la firma y el sello son siempre los del doctor que atendió**: en la
 * agenda es el doctor de la cita (`cola.medico_id`) y no el del contexto.
 */
class ImpresionDeDocumentosController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private ConsultasDelContexto $consultas,
        private DocumentosParaImprimir $documentos,
        private MembreteDelMedico $membretes,
        private MedicoQueFirma $firma,
        private ProximaCitaDelRecipe $proximaCita,
        private DocumentosPorCita $porCita,
        private QrParaLosRecipes $qr,
    ) {
    }

    // ---------------------------------------------------------------------------------- El médico

    /**
     * La vista previa de un documento de la consulta —o de todos (`?documento=todos`)—, desde la consulta del médico.
     * Sin `documento`, o con uno que la consulta no tiene, es 404.
     */
    public function delaConsulta(Request $request, Consulta $consulta)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        $consulta = $this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']);
        $paciente = $this->consultas->paciente($consulta, $registros);


        abort_unless($paciente !== null, 404);

        // El médico puede imprimir la constancia aunque no tenga nada propio; «todos» no la incluye en ese caso.
        $pedido = (string) $request->query('documento', '');
        $ofrecidos = $this->documentos->disponibles($consulta, $registros, $pedido === DocumentosParaImprimir::TODOS ? false : true);
        $claves = array_column($ofrecidos, 'clave');

        $elegidos = $pedido === DocumentosParaImprimir::TODOS ? $claves : (in_array($pedido, $claves, true) ? [$pedido] : []);

        // O-16: el documento todavía no existe (el estudio no se guardó, la solicitud está vacía). No es un 404: se dice qué
        // falta para poder imprimir y se ofrece la vuelta a la sección, en vez de la página «No encontramos esa página».
        if ($elegidos === []) {
            return view('clinica.imprimir.sin-documento', [
                'vm' => new SinDocumentoVista(
                    $this->nombreDelDocumento($pedido, $ofrecidos),
                    [
                        // La vuelta es a la sección donde se carga ese documento, no a una pestaña cualquiera: el médico
                        // acaba de apretar «Imprimir» ahí.
                        'volver' => $this->urlDeLaSeccion($consulta, $this->seccionDeLaClave($pedido)),
                        'volverTexto' => 'Volver a la consulta',
                    ],
                ),
            ]);
        }

        return $this->vistaPrevia(
            $request,
            $consulta,
            (int) $paciente->id,
            $registros,
            $elegidos,
            $this->urlDeLaConsulta($consulta, $ofrecidos, $elegidos),
            'Volver a la consulta',
            // La gesta del control de embarazo (`&gesta=2`): el informe prenatal es de una gesta y no de la
            // consulta, así que se pasa explícita en vez de que la lea el motor de documentos.
            ((int) $request->query('gesta', 0)) ?: null,
        );
    }

    // ------------------------------------------------------------------------- La cita (secretaría)

    /**
     * Los documentos de una cita **atendida**: la lista para elegir qué imprimir. Si la cita tiene **uno solo**, la lista se
     * salta y va directo a su vista previa; si no tiene ninguno, la pantalla lo dice (no es un error: la consulta pudo no
     * dejar nada para imprimir).
     */
    public function listaDeLaCita(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);
        $dia = Carbon::parse($cita->fecha)->toDateString();
        $atendida = (int) $cita->atendido === 1 && ! $cita->movida_escritorio;
        $registros = $this->registrosDelMedico($contexto['medico']);

        $de = $this->porCita->deLaCita($cita, $registros);
        $disponibles = $atendida && $de['consulta'] !== null ? $this->documentos->disponibles($de['consulta'], $registros) : [];

        if (count($disponibles) === 1) {
            return redirect()->route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => $disponibles[0]['clave']]);
        }

        // Las opciones de la hoja son del **doctor que atendió** la cita (el de la firma), no del médico del contexto:
        // en la secretaría de un centro el contexto es la fila del centro (2026-10-20).
        $firmante = $this->firma->deUnaCita($cita, $contexto['medico']) ?? $contexto['medico'];

        return view('clinica.imprimir.documentos-de-la-cita', [
            'vm' => new DocumentosDeLaCitaVista(
                $de['nombre'],
                $dia,
                $atendida,
                $disponibles,
                route('clinica.agenda', ['vista' => 'dia', 'fecha' => $dia, 'sede' => 'todas']),
                fn (string $clave) => route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => $clave]),
                new OpcionesDeImpresionVista($this->membretes->opciones($firmante), route('clinica.recipes.opciones')),
            ),
        ]);
    }

    /**
     * La vista previa de los documentos de una cita atendida: `?documento=recipe-2`, `reposo`, `referencia-3`… o `todos`. Sin
     * `documento` se imprime el único que tenga, si es uno solo; si hay varios hay que elegir (404 si no).
     */
    public function imprimirDeLaCita(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);
        $registros = $this->registrosDelMedico($contexto['medico']);

        abort_if($cita->movida_escritorio || (int) $cita->atendido !== 1, 404);

        $de = $this->porCita->deLaCita($cita, $registros);
        abort_if($de['consulta'] === null || $de['pacienteId'] === null, 404);

        $disponibles = $this->documentos->disponibles($de['consulta'], $registros);
        $claves = array_column($disponibles, 'clave');
        $pedido = (string) $request->query('documento', count($claves) === 1 ? $claves[0] : '');

        $elegidos = $pedido === DocumentosParaImprimir::TODOS ? $claves : (in_array($pedido, $claves, true) ? [$pedido] : []);
        abort_if($elegidos === [], 404);

        $dia = Carbon::parse($cita->fecha)->toDateString();

        return $this->vistaPrevia(
            $request,
            $de['consulta'],
            (int) $de['pacienteId'],
            $registros,
            $elegidos,
            count($disponibles) > 1
                ? route('clinica.agenda.documentos', $cita->id)
                : route('clinica.agenda', ['vista' => 'dia', 'fecha' => $dia, 'sede' => 'todas']),
            count($disponibles) > 1 ? 'Volver a los documentos de la cita' : 'Volver a la agenda',
            // **El doctor que atendió la cita** (Fase 5): la secretaría imprime con el membrete del formato del
            // consultorio y **la firma del doctor asignado**, no la del centro.
            null,
            $this->firma->deUnaCita($cita, $contexto['medico']),
        );
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * La hoja: una sola implementación para los dos caminos. Arma los documentos pedidos, el membrete del formato del
     * consultorio (el del centro o el del doctor), las opciones de impresión que quedaron guardadas —las del **doctor
     * que firma**— y la próxima cita del paciente, y devuelve la página de impresión (sin el armazón de la web, como el
     * listado del día y el resumen de la historia).
     *
     * **Quién firma** (Fase 5 del cambio de `reg_medico`): el doctor de la cita (`$firmante`, que resuelve la secretaría) o,
     * si no se pasa, el de la consulta; y si la consulta tampoco lo dice, el del contexto. De él salen la firma, el sello y
     * el QR. **Con qué membrete** sale lo decide `MembreteDelMedico::deLaImpresion` con el `reg_medico` de la consulta.
     *
     * **Pediatría** se decide acá, con la especialidad del contexto (`AlcanceDeLaEspecialidad`) y **no** con la edad del
     * paciente: sus documentos llevan la cabecera del escritorio (sexo, representante, historia y edad compacta). Del resto
     * de las especialidades, la hoja no cambia.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @param  array<int,string>  $claves  `recipe-2`, `reposo`, `referencia-3`… lo que se imprime, en orden
     * @param  Medico|null  $firmante  el doctor que atendió la cita; `null` = el de la consulta o el del contexto
     */
    private function vistaPrevia(Request $request, Consulta $consulta, int $pacienteId, array $registros, array $claves, string $volver, string $textoVolver, ?int $gesta = null, ?Medico $firmante = null)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $pediatria = AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria();
        $firmante = $firmante ?? ($this->firma->deUnaConsulta($consulta, $contexto['medico']) ?? $contexto['medico']);

        $documentos = [];

        foreach ($claves as $clave) {
            $documento = $this->documentos->documento($consulta, $pacienteId, $registros, $clave, $gesta, $pediatria);

            // Un documento que no se pudo armar (dos pestañas abiertas y el estudio se borró en el medio) **no rompe el
            // lote**: se saltea. Si no queda ninguno, sí es un 404 (O-16: el lote de la cita no se cae por uno).
            if ($documento !== null) {
                $documentos[] = $documento;
            }
        }

        abort_if($documentos === [], 404);

        // La página de las solicitudes (`?pagina=grande|pequena`, el «Página» del escritorio): los demás la ignoran.
        $pagina = ModoDeLaHoja::pagina($request->query('pagina'));
        $documentos = array_map(fn ($documento) => $documento instanceof DocumentoImpreso ? $documento->conPagina($pagina) : $documento, $documentos);

        // El QR de verificación de cada récipe (WEB-5.6): el mismo que imprime el médico, sea quien sea quien imprima, y
        // avalado por el doctor que firma.
        $documentos = $this->qr->agregar($documentos, $consulta, $firmante);

        // Las opciones de la hoja son del **doctor que firma**, no del médico del contexto (en la secretaría de un
        // centro el contexto es el centro): salen de su fila de `recipe_formatos` aunque el membrete sea del centro.
        $opciones = $this->membretes->opciones($firmante);

        return view('clinica.imprimir.hojas', [
            'vm' => new HojasImpresasVista(
                $this->membretes->deLaImpresion($firmante, (string) $consulta->reg_medico),
                $opciones,
                $documentos,
                $this->proximaCita->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, $pacienteId),
                ['volver' => $volver, 'volverTexto' => $textoVolver],
                new OpcionesDeImpresionVista($opciones, route('clinica.recipes.opciones'), $request->getRequestUri()),
                $pediatria,
                [
                    ModoDeLaHoja::PAGINA_GRANDE => $request->fullUrlWithQuery(['pagina' => ModoDeLaHoja::PAGINA_GRANDE]),
                    ModoDeLaHoja::PAGINA_PEQUENA => $request->fullUrlWithQuery(['pagina' => ModoDeLaHoja::PAGINA_PEQUENA]),
                ],
            ),
        ]);
    }

    /**
     * A dónde vuelve «Volver» desde la hoja de la consulta: a la pestaña del documento que se imprimió o, si se imprimieron
     * varios, a la consulta.
     *
     * @param  array<int,array{clave:string, tipo:string}>  $ofrecidos
     * @param  array<int,string>  $elegidos
     */
    private function urlDeLaConsulta(Consulta $consulta, array $ofrecidos, array $elegidos): string
    {
        if (count($elegidos) !== 1) {
            return route('clinica.consulta', $consulta->id);
        }

        return $this->urlDeLaSeccion($consulta, collect($ofrecidos)->firstWhere('clave', $elegidos[0])['tipo'] ?? null);
    }

    /** Cómo se llama el documento cuya hoja se pidió, para el aviso de «no hay nada que imprimir» (O-16). */
    private function nombreDelDocumento(string $pedido, array $ofrecidos): ?string
    {
        if (isset(DocumentoImpreso::TITULOS[$pedido])) {
            return DocumentoImpreso::TITULOS[$pedido][1];
        }

        return collect($ofrecidos)->firstWhere('clave', $pedido)['titulo'] ?? null;
    }

    /**
     * La clave de un documento pedido, llevada a la clave de su **tipo** (`referencia-3` → `referencia`, `recipe-2` →
     * `recipe`): es lo que `urlDeLaSeccion()` compara.
     */
    private function seccionDeLaClave(string $pedido): string
    {
        if (preg_match('/^' . DocumentoImpreso::REFERENCIA . '-\d+$/', $pedido) === 1) {
            return DocumentoImpreso::REFERENCIA;
        }

        return preg_match('/^recipe-\d+$/', $pedido) === 1 ? 'recipe' : $pedido;
    }

    /**
     * La sección de la consulta adonde vuelve una hoja (y adonde manda el aviso de «no hay nada que imprimir», O-16): cada
     * documento vuelve a la pestaña —o al tipo del estudio ecográfico— donde se carga.
     */
    private function urlDeLaSeccion(Consulta $consulta, ?string $tipo): string
    {
        if ($tipo === 'recipe') {
            return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]);
        }

        // El informe del estudio ecográfico no es una pestaña de las solicitudes: vuelve a su sección
        // (el ítem 5 del panel, WEB-6.1), en el tipo que existe.
        if ($tipo === DocumentoImpreso::ECO_PELVICO) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::GINECOLOGICO,
            ]);
        }

        // El eco Doppler perinatal (WEB-6.3) es otro tipo de esa misma sección: vuelve a él.
        if ($tipo === DocumentoImpreso::ECO_DOPPLER) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::DOPPLER,
            ]);
        }
        // Los otros tres estudios (WEB-6.3/6.4) son tipos de la misma seccion: cada uno vuelve al suyo.
        if ($tipo === DocumentoImpreso::ECO_OBSTETRICO) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::OBSTETRICO,
            ]);
        }

        if ($tipo === DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::SEGUNDO,
            ]);
        }

        if ($tipo === DocumentoImpreso::ECO_OBSTETRICO_TERCERO) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::TERCERO,
            ]);
        }

        if ($tipo === DocumentoImpreso::ECO_CARDIOGRAMA_FETAL) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::FETAL,
            ]);
        }

        if ($tipo === DocumentoImpreso::ECO_MAMARIO) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::MAMARIO,
            ]);
        }

        if ($tipo === DocumentoImpreso::ECO_ABDOMINAL) {
            return route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
                'tipo' => TiposDeEstudio::ABDOMINAL,
            ]);
        }
        // El resumen médico (WEB-5.3c) no tiene pestaña propia: su botón vive en la pestaña *Informe
        // médico* del escritorio (`tabpage_14`), que es a donde vuelve.
        if ($tipo === DocumentoImpreso::RESUMEN_MEDICO) {
            return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::INFORME]);
        }

        $pestana = $tipo === DocumentoImpreso::REFERENCIA ? PestanasDeSolicitudes::REFERENCIAS : ($tipo ?? PestanasDeSolicitudes::POR_DEFECTO);

        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $pestana]);
    }
}
