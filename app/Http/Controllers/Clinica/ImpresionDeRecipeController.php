<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Recipes\GuardarOpcionesDeImpresion;
use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\MedicoQueFirma;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Clinica\Impresion\QrParaLosRecipes;
use App\Clinica\Impresion\RecipesParaImprimir;
use App\Clinica\Impresion\Vistas\HojasDeRecipeVista;
use App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\RecipeFormato;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Imprimir el récipe** (WEB-5.2): la vista previa de la hoja A4 horizontal que imprime el navegador
 * (PLAN-WEB.md R6, decisión 17) y la elección de cómo sale.
 *
 * Este controlador es **el del médico en la consulta**: la hoja de un récipe del paciente
 * (`/clinica/consulta/{consulta}/recipes/{n}/imprimir`) o la de la pareja (`/recipe-pareja/imprimir`), en el grupo
 * `role:Root|Medico` de la consulta. Desde WEB-5.3a **la secretaría** imprime desde la cita atendida por
 * `ImpresionDeDocumentosController` (la lista de todo lo imprimible de la cita y su vista previa), que arma la misma hoja del
 * récipe con `HojasImpresasVista`.
 *
 * La elección de cómo sale (`POST /clinica/recipes/opciones-de-impresion`) es del **médico del contexto** y la
 * puede hacer cualquiera de los dos: lo que elige la asistente es lo que ve el médico (decisión del usuario,
 * 2026-10-11). Vive acá porque es la opción **del récipe** (el modo y el nombre del medicamento); los demás documentos no
 * la usan.
 *
 * Todo se resuelve contra el **contexto**: una consulta de otro consultorio responde 404 sin confirmar que existe, y el
 * membrete es el del médico del contexto. Desde la Fase 5 del cambio de `reg_medico` el membrete es el del **centro**
 * cuando ese consultorio lo eligió (`formato_reportes`), y **la firma y el sello siguen siendo los del doctor que
 * atendió** (`MedicoQueFirma` + `MembreteDelMedico::deLaImpresion`).
 */
class ImpresionDeRecipeController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    /**
     * Las URLs a las que se puede **seguir** después de guardar las opciones: sólo las vistas previas de la web
     * clínica (`…/imprimir`, con su `?recipe=` o `?documento=`), para que el campo `siguiente` del formulario no sirva
     * de redirección abierta.
     */
    private const SIGUIENTE_VALIDO = '#^/clinica/[A-Za-z0-9_/-]+/imprimir(\?[A-Za-z0-9_=&%:.-]*)?$#';

    public function __construct(
        private ConsultasDelContexto $consultas,
        private RecipesParaImprimir $recipes,
        private MembreteDelMedico $membretes,
        private MedicoQueFirma $firma,
        private ProximaCitaDelRecipe $proximaCita,
        private QrParaLosRecipes $qr,
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
     * La hoja: una sola implementación para los dos caminos. Arma los récipes pedidos, el membrete del formato del
     * consultorio (el del centro o el del doctor), las opciones que quedaron guardadas y la próxima cita del paciente, y
     * devuelve la página de impresión (sin el armazón de la web, como el listado del día y el resumen de la historia).
     *
     * **Quién firma** (Fase 5 del cambio de `reg_medico`) es el doctor de la consulta —o, si la consulta no lo dice, el
     * del contexto—: de él salen la firma, el sello y el QR de verificación. **Con qué membrete** sale lo decide
     * `MembreteDelMedico::deLaImpresion` con el `reg_medico` de la consulta.
     *
     * **Pediatría** se decide acá, con la especialidad del contexto (`AlcanceDeLaEspecialidad`) y **no** con la edad
     * del paciente —un paciente de pediatría puede tener 21 años—: su récipe lleva la cabecera del escritorio y las
     * medidas de la consulta. Del resto de las especialidades, la hoja no cambia.
     *
     * @param  array<int,string>  $claves  `'2'`, `'pareja'`… los récipes de la consulta que se imprimen, en orden
     */
    private function vistaPrevia(Request $request, Consulta $consulta, int $pacienteId, array $claves, string $volver, string $textoVolver)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $pediatria = AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria();
        $firmante = $this->firma->deUnaConsulta($consulta, $contexto['medico']) ?? $contexto['medico'];

        $documentos = [];
        foreach ($claves as $clave) {
            $documento = $this->recipes->documento($consulta, $pacienteId, $clave, $pediatria);
            abort_if($documento === null, 404);
            $documentos[] = $documento;
        }

        // El QR de verificación de cada récipe (WEB-5.6): se obtiene o se crea al imprimir y es el mismo cada vez. El
        // doctor que lo avala es el que firma, no el centro.
        $documentos = $this->qr->agregar($documentos, $consulta, $firmante);

        $opciones = $this->membretes->opciones($contexto['medico']);

        return view('clinica.imprimir.hojas', [
            'vm' => new HojasDeRecipeVista(
                $this->membretes->deLaImpresion($firmante, (string) $consulta->reg_medico),
                $opciones,
                $documentos,
                $this->proximaCita->de((string) $consulta->reg_medico, (int) $consulta->numhistoria, $pacienteId),
                ['volver' => $volver, 'volverTexto' => $textoVolver],
                new OpcionesDeImpresionVista($opciones, route('clinica.recipes.opciones'), $request->getRequestUri()),
                $pediatria,
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
