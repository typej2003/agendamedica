<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Recipes\GuardarOpcionesDeImpresion;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Impresion\RecipesParaImprimir;
use App\Clinica\Impresion\RecipesPorCita;
use App\Clinica\Impresion\Vistas\HojasDeRecipeVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Clinica\Impresion\Vistas\RecipesDeLaCitaVista;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\RecipeFormato;
use Illuminate\Http\Request;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * **Imprimir el récipe** (WEB-5.2): la vista previa de la hoja A4 horizontal que imprime el navegador
 * (PLAN-WEB.md R6, decisión 17) y la elección de cómo sale.
 *
 * Hay **dos caminos a la misma hoja**, y los dos terminan en `vistaPrevia()` —una sola implementación de la hoja y
 * de la regla— :
 *
 *  - **El médico**, desde la consulta (`/clinica/consulta/{consulta}/recipes/{n}/imprimir` y
 *    `/recipe-pareja/imprimir`) o desde la historia del paciente. Va en el grupo `role:Root|Medico` de la consulta.
 *  - **La secretaría** —y también el médico—, desde la fila de una cita **ya atendida** de la agenda
 *    (`/clinica/agenda/{cola}/recipes` y `/imprimir`). La secretaría **no entra a la consulta** ni a la ficha
 *    clínica, y esta ruta no se la abre: sólo **imprime**. Lo único clínico que ve es lo que sale en la hoja y la
 *    lista de qué récipes tiene esa cita para elegir cuál imprimir.
 *
 * La elección de cómo sale (`POST /clinica/recipes/opciones-de-impresion`) es del **médico del contexto** y la
 * puede hacer cualquiera de los dos: lo que elige la asistente es lo que ve el médico (decisión del usuario,
 * 2026-10-11).
 *
 * Todo se resuelve contra el **contexto**: una consulta o una cita de otro consultorio responde 404 sin confirmar
 * que existe, y el membrete es el del médico del contexto.
 */
class ImpresionDeRecipeController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    /**
     * Las URLs a las que se puede **seguir** después de guardar las opciones: sólo las vistas previas de la web
     * clínica (`…/imprimir`, con su `?recipe=`), para que el campo `siguiente` del formulario no sirva de
     * redirección abierta.
     */
    private const SIGUIENTE_VALIDO = '#^/clinica/[A-Za-z0-9_/-]+/imprimir(\?[A-Za-z0-9_=&%:.-]*)?$#';

    public function __construct(
        private ConsultasDelContexto $consultas,
        private RecipesParaImprimir $recipes,
        private MembreteDelMedico $membretes,
        private ProximaCitaDelRecipe $proximaCita,
        private RecipesPorCita $porCita,
    ) {
    }

    // ---------------------------------------------------------------------------------- El médico

    /** La vista previa de un récipe del paciente, desde su consulta. */
    public function delPaciente(Request $request, Consulta $consulta, int $numero)
    {
        [$consulta, $pacienteId] = $this->delContexto($request, $consulta);

        return $this->vistaPrevia(
            $request,
            $consulta,
            $pacienteId,
            [(string) $numero],
            route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]),
            'Volver a los récipes',
        );
    }

    /** La vista previa del récipe de la pareja, desde su consulta. */
    public function dePareja(Request $request, Consulta $consulta)
    {
        [$consulta, $pacienteId] = $this->delContexto($request, $consulta);

        return $this->vistaPrevia(
            $request,
            $consulta,
            $pacienteId,
            [DocumentoDeRecipe::PAREJA],
            route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'recipe' => ConsultaShellVista::RECIPE_PAREJA]),
            'Volver a los récipes',
        );
    }

    // ------------------------------------------------------------------------- La cita (secretaría)

    /**
     * Los récipes de una cita **atendida**: la lista para elegir cuál imprimir. Si la cita tiene **uno solo**, la
     * lista se salta y va directo a su vista previa; si no tiene ninguno, la pantalla lo dice (no es un error: la
     * consulta pudo no recetar nada).
     */
    public function recipesDeLaCita(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);
        $dia = Carbon::parse($cita->fecha)->toDateString();
        $atendida = (int) $cita->atendido === 1 && ! $cita->movida_escritorio;

        $de = $this->porCita->deLaCita($cita, $this->registrosDelMedico($contexto['medico']));
        $disponibles = $atendida && $de['consulta'] !== null ? $this->recipes->disponibles($de['consulta']) : [];

        if (count($disponibles) === 1) {
            return redirect()->route('clinica.agenda.recipes.imprimir', [$cita->id, 'recipe' => $disponibles[0]['clave']]);
        }

        return view('clinica.imprimir.recipes-de-la-cita', [
            'vm' => new RecipesDeLaCitaVista(
                $de['nombre'],
                $dia,
                $atendida,
                $disponibles,
                route('clinica.agenda', ['vista' => 'dia', 'fecha' => $dia, 'sede' => 'todas']),
                fn (string $clave) => route('clinica.agenda.recipes.imprimir', [$cita->id, 'recipe' => $clave]),
                new OpcionesDeImpresionVista($this->membretes->opciones($contexto['medico']), route('clinica.recipes.opciones')),
            ),
        ]);
    }

    /**
     * La vista previa de los récipes de una cita atendida: `?recipe=2`, `?recipe=pareja` o `?recipe=todos`.
     * Sin `recipe` se imprime el único que tenga, si es uno solo; si hay varios hay que elegir (404 si no).
     */
    public function imprimirDeLaCita(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);

        abort_if($cita->movida_escritorio || (int) $cita->atendido !== 1, 404);

        $de = $this->porCita->deLaCita($cita, $this->registrosDelMedico($contexto['medico']));
        abort_if($de['consulta'] === null || $de['pacienteId'] === null, 404);

        $disponibles = $this->recipes->disponibles($de['consulta']);
        $claves = array_column($disponibles, 'clave');
        $pedido = (string) $request->query('recipe', count($claves) === 1 ? $claves[0] : '');

        $elegidos = $pedido === 'todos' ? $claves : (in_array($pedido, $claves, true) ? [$pedido] : []);
        abort_if($elegidos === [], 404);

        return $this->vistaPrevia(
            $request,
            $de['consulta'],
            $de['pacienteId'],
            $elegidos,
            count($disponibles) > 1
                ? route('clinica.agenda.recipes', $cita->id)
                : route('clinica.agenda', ['vista' => 'dia', 'fecha' => Carbon::parse($cita->fecha)->toDateString(), 'sede' => 'todas']),
            count($disponibles) > 1 ? 'Volver a los récipes de la cita' : 'Volver a la agenda',
        );
    }

    // ------------------------------------------------------------------------------------ Las opciones

    /**
     * Guarda cómo sale la hoja (modo y nombre del medicamento) en el formato del médico del contexto y sigue a la
     * vista previa. La hacen el médico y la secretaría: las opciones son **del médico** y se comparten.
     */
    public function guardarOpciones(Request $request, GuardarOpcionesDeImpresion $guardar)
    {
        // Los botones que abren el diálogo llevan la URL **absoluta** de la vista previa (`route()`): si es de este
        // mismo sitio se reduce a su ruta, y cualquier otra cosa la rechaza la validación de `siguiente`.
        $request->merge(['siguiente' => $this->rutaDeEsteSitio($request->input('siguiente'), $request)]);

        $datos = $request->validate([
            'modo' => ['required', 'in:' . implode(',', RecipeFormato::MODOS_IMPRESION)],
            'nombre' => ['required', 'in:' . implode(',', RecipeFormato::NOMBRES_MEDICAMENTO)],
            'siguiente' => ['required', 'string', 'max:300', 'regex:' . self::SIGUIENTE_VALIDO],
        ], [
            'modo.required' => 'Elige qué se imprime: Rp. + Indicaciones o solo las indicaciones.',
            'modo.in' => 'El modo de impresión elegido no es válido.',
            'nombre.required' => 'Elige con qué nombre sale el medicamento: comercial o genérico.',
            'nombre.in' => 'El nombre del medicamento elegido no es válido.',
            'siguiente.required' => 'No se sabe a qué récipe volver: ábrelo de nuevo desde su lista.',
            'siguiente.regex' => 'No se sabe a qué récipe volver: ábrelo de nuevo desde su lista.',
        ]);

        $contexto = $request->attributes->get('contexto_clinico');

        try {
            $guardar->ejecutar($contexto['medico'], $datos['modo'], $datos['nombre']);
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        return redirect($datos['siguiente']);
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * La hoja: una sola implementación para los dos caminos. Arma los récipes pedidos, el membrete del médico del
     * contexto, las opciones que quedaron guardadas y la próxima cita del paciente, y devuelve la página de
     * impresión (sin el armazón de la web, como el listado del día y el resumen de la historia).
     *
     * @param  array<int,string>  $claves  `'2'`, `'pareja'`… los récipes de la consulta que se imprimen, en orden
     */
    private function vistaPrevia(Request $request, Consulta $consulta, int $pacienteId, array $claves, string $volver, string $textoVolver)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $documentos = [];
        foreach ($claves as $clave) {
            $documento = $this->recipes->documento($consulta, $pacienteId, $clave);
            abort_if($documento === null, 404);
            $documentos[] = $documento;
        }

        $opciones = $this->membretes->opciones($contexto['medico']);

        return view('clinica.imprimir.recipe', [
            'vm' => new HojasDeRecipeVista(
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
     * Una URL de **este mismo sitio** como ruta (`/clinica/…?recipe=1`). Lo demás —otro sitio, un `//otro.com`, algo que
     * no es texto— se devuelve tal cual para que la validación lo rechace: acá no se decide qué es válido, sólo se quita el
     * origen propio.
     *
     * @param  mixed  $url
     * @return mixed
     */
    private function rutaDeEsteSitio($url, Request $request)
    {
        if (! is_string($url)) {
            return $url;
        }

        $partes = parse_url($url);

        if ($partes === false || ! isset($partes['host']) || ! in_array($partes['scheme'] ?? '', ['http', 'https'], true)) {
            return $url;
        }

        if (strcasecmp($partes['host'], $request->getHost()) !== 0 || isset($partes['user']) || isset($partes['pass'])) {
            return $url;
        }

        return ($partes['path'] ?? '/') . (isset($partes['query']) ? '?' . $partes['query'] : '');
    }

    /**
     * La consulta del contexto y el id de su paciente. Una consulta de otro consultorio —o sin paciente— es 404.
     *
     * @return array{0:Consulta, 1:int}
     */
    private function delContexto(Request $request, Consulta $consulta): array
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        $consulta = $this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']);
        $paciente = $this->consultas->paciente($consulta, $registros);

        abort_unless($paciente !== null, 404);

        return [$consulta, (int) $paciente->id];
    }
}
