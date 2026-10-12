<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Impresion\QrParaLosRecipes;
use App\Clinica\Impresion\Vistas\DocumentosDeLaCitaVista;
use App\Clinica\Impresion\Vistas\HojasImpresasVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Consulta;
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
 * el membrete es el del médico del contexto.
 */
class ImpresionDeDocumentosController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private ConsultasDelContexto $consultas,
        private DocumentosParaImprimir $documentos,
        private MembreteDelMedico $membretes,
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
        abort_if($elegidos === [], 404);

        return $this->vistaPrevia(
            $request,
            $consulta,
            (int) $paciente->id,
            $registros,
            $elegidos,
            $this->urlDeLaConsulta($consulta, $ofrecidos, $elegidos),
            'Volver a la consulta',
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

        return view('clinica.imprimir.documentos-de-la-cita', [
            'vm' => new DocumentosDeLaCitaVista(
                $de['nombre'],
                $dia,
                $atendida,
                $disponibles,
                route('clinica.agenda', ['vista' => 'dia', 'fecha' => $dia, 'sede' => 'todas']),
                fn (string $clave) => route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => $clave]),
                new OpcionesDeImpresionVista($this->membretes->opciones($contexto['medico']), route('clinica.recipes.opciones')),
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
        );
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * La hoja: una sola implementación para los dos caminos. Arma los documentos pedidos, el membrete del médico del contexto,
     * las opciones de impresión que quedaron guardadas (de los récipes) y la próxima cita del paciente, y devuelve la página de
     * impresión (sin el armazón de la web, como el listado del día y el resumen de la historia).
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @param  array<int,string>  $claves  `recipe-2`, `reposo`, `referencia-3`… lo que se imprime, en orden
     */
    private function vistaPrevia(Request $request, Consulta $consulta, int $pacienteId, array $registros, array $claves, string $volver, string $textoVolver)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $documentos = [];
        foreach ($claves as $clave) {
            $documento = $this->documentos->documento($consulta, $pacienteId, $registros, $clave);
            abort_if($documento === null, 404);
            $documentos[] = $documento;
        }

        // El QR de verificación de cada récipe (WEB-5.6): el mismo que imprime el médico, sea quien sea quien imprima.
        $documentos = $this->qr->agregar($documentos, $consulta, $contexto['medico']);

        $opciones = $this->membretes->opciones($contexto['medico']);

        return view('clinica.imprimir.hojas', [
            'vm' => new HojasImpresasVista(
                $this->membretes->de($contexto['medico']),
                $opciones,
                $documentos,
                $this->proximaCita->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, $pacienteId),
                ['volver' => $volver, 'volverTexto' => $textoVolver],
                new OpcionesDeImpresionVista($opciones, route('clinica.recipes.opciones'), $request->getRequestUri()),
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

        $tipo = collect($ofrecidos)->firstWhere('clave', $elegidos[0])['tipo'] ?? null;

        if ($tipo === 'recipe') {
            return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]);
        }

        $pestana = $tipo === DocumentoImpreso::REFERENCIA ? PestanasDeSolicitudes::REFERENCIAS : ($tipo ?? PestanasDeSolicitudes::POR_DEFECTO);

        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => $pestana]);
    }
}
