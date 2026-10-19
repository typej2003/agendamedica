<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Actions\Consulta\EliminarRecipe;
use App\Actions\Consulta\GuardarRecipe;
use App\Actions\Consulta\ResultadoDeRecipe;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DosisPediatrica;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ItemsDeUnRecipeEnElFormulario;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Support\FechaDeFormulario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * **Los récipes de una consulta** (WEB-5.1, ítem 10 del panel del legado): crear uno, editarlo en el lugar,
 * eliminarlo, buscar en el vademécum y en los tratamientos del médico, y crear el medicamento que falta.
 *
 * La sección se **lee** en `ConsultaController::ver` (`?seccion=recipes`, con `&recipe=nuevo` o `&recipe=2`
 * para el editor); acá están las escrituras y los dos buscadores JSON, que son acciones y por eso viven en
 * un controlador propio (la guía de la web parte los controladores por responsabilidad, no por pantalla).
 *
 * Tres decisiones que se ven en el código:
 *
 *  - **El `reg_medico` sale del contexto**, nunca del formulario: la consulta se resuelve contra el
 *    médico del contexto (una consulta de otro consultorio da **404** sin confirmar que exista) y el
 *    vademécum y los tratamientos son los de ese médico.
 *  - **La regla de negocio no está acá**: crear y editar es `App\Actions\Consulta\GuardarRecipe` —la
 *    misma que usa el sync del móvil—, eliminar es `EliminarRecipe` y el alta del vademécum es
 *    `AgregarMedicamentoAlVademecum`. Este controlador valida el formulario, le pide a
 *    `RecipesDeLaConsulta` que compruebe los medicamentos contra los catálogos y cuenta qué pasó.
 *  - **El guardado es del récipe entero** y explícito: un solo `POST` con la fecha de emisión y todos sus
 *    medicamentos; la Action compara con lo que hay y escribe sólo lo que cambió.
 */
class ConsultaRecipesController extends Controller
{
    use ResuelveLosRegistrosDelMedico;
    use ItemsDeUnRecipeEnElFormulario;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar un récipe **nuevo**: le toca el número que sigue en la consulta. */
    public function crear(Request $request, Consulta $consulta, RecipesDeLaConsulta $lectura, GuardarRecipe $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        return $this->guardarRecipe($request, $consulta, null, $lectura, $guardar);
    }

    /** Guardar los cambios de un récipe que **ya existe**, en el lugar. */
    public function guardar(Request $request, Consulta $consulta, int $numero, RecipesDeLaConsulta $lectura, GuardarRecipe $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        abort_unless($lectura->existe($consulta, $numero), 404);

        return $this->guardarRecipe($request, $consulta, $numero, $lectura, $guardar);
    }

    /** Eliminar un récipe entero (filas y cabecera): siempre se puede. */
    public function eliminar(Request $request, Consulta $consulta, int $numero, RecipesDeLaConsulta $lectura, EliminarRecipe $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        abort_unless($lectura->existe($consulta, $numero), 404);

        $quitadas = $eliminar->ejecutar($consulta, $numero, null, 'web');

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES])
            ->with('estado', 'Eliminaste el récipe ' . $numero . ' de la consulta ('
                . $quitadas . ($quitadas === 1 ? ' medicamento' : ' medicamentos') . ').');
    }

    /**
     * El buscador de medicamentos del vademécum del médico: por nombre comercial o genérico, sin
     * distinguir tildes. Es JSON porque filtra mientras se escribe y no escribe nada.
     */
    public function medicamentos(Request $request, Consulta $consulta, RecipesDeLaConsulta $lectura, DosisPediatrica $dosis): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));

        // **La dosis del niño** (2026-10-19): cuando el medicamento es por kilo, el récipe propone las indicaciones
        // ya calculadas con el peso del examen físico de la consulta (`DosisPediatrica`, la fórmula del escritorio).
        // El médico las puede corregir: se cargan en su campo, no se guardan solas.
        $peso = $consulta->peso;

        return response()->json([
            'buscar' => $buscar,
            'medicamentos' => $lectura->medicamentos((string) $consulta->reg_medico, $buscar)
                ->map(function (object $medicamento) use ($dosis, $peso) {
                    $calculo = $dosis->calcular($medicamento, $peso);

                    return (array) $medicamento + [
                        'indicacionSugerida' => $calculo['texto'] ?? null,
                        'dosisCalculada' => $calculo,
                    ];
                })
                ->values()->all(),
        ]);
    }

    /**
     * El buscador de tratamientos del médico, **con sus medicamentos**: un clic en la pantalla los agrega
     * todos al récipe en edición, así que no hace falta una segunda petición.
     */
    public function tratamientos(Request $request, Consulta $consulta, RecipesDeLaConsulta $lectura): JsonResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $buscar = trim((string) $request->query('q', ''));

        return response()->json([
            'buscar' => $buscar,
            'tratamientos' => $lectura->tratamientos((string) $consulta->reg_medico, $buscar)
                ->map(fn (object $tratamiento) => [
                    'codigo' => $tratamiento->codigo,
                    'nombre' => $tratamiento->nombre,
                    'items' => $tratamiento->items->values()->all(),
                ])->values()->all(),
        ]);
    }

    /**
     * **Crear el medicamento que falta** en el vademécum del médico (el botón *Agregar* del escritorio,
     * `w_nuevo_vademecum`). Online-only. Si ya existe uno igual (mismo nombre comercial y presentación) se
     * usa el existente y no se duplica.
     *
     * Responde JSON cuando se pide así (el script de la sección lo hace para no perder lo que hay en el
     * editor); sin JavaScript vuelve a la sección con el aviso.
     */
    public function crearMedicamento(Request $request, Consulta $consulta, AgregarMedicamentoAlVademecum $alta, RecipesDeLaConsulta $lectura)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // `nullable`: el texto vacío no es un error de formulario sino la regla de negocio de la Action,
            // y el mensaje tiene que ser el suyo (igual que el alta de motivos y de diagnósticos).
            'nombrecomercial' => 'nullable|string|max:200',
            'nombregenerico' => 'nullable|string|max:200',
            'presentacion' => 'nullable|string|max:200',
            'uso' => 'nullable|string|max:' . (AgregarMedicamentoAlVademecum::LARGO_USO * 2),
        ], [
            'nombrecomercial.max' => 'El nombre del medicamento es demasiado largo.',
            'nombregenerico.max' => 'El principio activo es demasiado largo.',
            'presentacion.max' => 'La presentación es demasiado larga.',
            'uso.max' => 'Las indicaciones son demasiado largas.',
        ]);

        try {
            [$medicamento, $creado] = $alta->ejecutar(
                (string) $consulta->reg_medico,
                $this->registrosDelMedico($contexto['medico']),
                (string) ($datos['nombrecomercial'] ?? ''),
                $datos['nombregenerico'] ?? null,
                $datos['presentacion'] ?? null,
                $datos['uso'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES])
                ->withInput()
                ->withErrors(['nombrecomercial' => $e->getMessage()]);
        }

        $delVademecum = $lectura->delVademecum((string) $consulta->reg_medico, $medicamento->codemedicina);

        if ($request->expectsJson()) {
            return response()->json(['creado' => $creado, 'medicamento' => $delVademecum], $creado ? 201 : 200);
        }

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES])
            ->with('estado', ($creado
                ? 'Creaste «' . ($delVademecum->nombre ?? $medicamento->codemedicina) . '» en tu vademécum'
                : '«' . ($delVademecum->nombre ?? $medicamento->codemedicina) . '» ya estaba en tu vademécum')
                . '; búscalo para agregarlo al récipe.');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** Valida el formulario del editor, comprueba los medicamentos y llama a la Action. */
    private function guardarRecipe(
        Request $request,
        Consulta $consulta,
        ?int $numero,
        RecipesDeLaConsulta $lectura,
        GuardarRecipe $guardar,
    ): RedirectResponse {
        // La fecha de emisión se pasa a `AAAA-MM-DD` en el único lugar donde esa regla está escrita (acepta
        // también lo que llegue tecleado `dd/mm/aaaa`): las columnas `fe_emision` y `recipes.fecha` son `date`.
        $request->merge(FechaDeFormulario::normalizar($request->all(), ['fecha']));

        $datos = $request->validate($this->reglas(), $this->mensajes());

        $resolucion = $lectura->resolverItems($consulta, $numero, (array) $datos['items']);

        if ($resolucion['desconocidos'] !== []) {
            // Un medicamento que no está en el vademécum del médico (ni en sus tratamientos) no se puede
            // recetar: `recipes.codemedicina` es obligatorio y no hay texto libre. Se vuelve al editor con
            // lo escrito para que se corrija (guía de UI §3).
            return $this->volverAlEditor($request, $consulta, $numero)->withErrors([
                'items' => 'Hay medicamentos que no están en tu vademécum: '
                    . implode(', ', $resolucion['desconocidos']) . '. Quítalos o créalos en el vademécum.',
            ]);
        }

        $resultado = $guardar->ejecutar(
            $consulta,
            $resolucion['items'],
            $datos['fecha'] ?? null,
            $numero,
            null,
            'web',
        );

        $destino = $this->alEditor($consulta, $numero ?? $resultado->numero);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a abrir el récipe y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'El récipe ' . $resultado->numero . ' no tenía cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** @return array<string,mixed> */
    private function reglas(): array
    {
        return ['fecha' => 'nullable|date_format:Y-m-d'] + $this->reglasDeLosItems();
    }

    /**
     * Un aviso por regla: el `locale` de la app es `en` y la validación sale en inglés si no se le da su
     * texto (trampa 38 de `TRAMPAS.md`). Los de los medicamentos son los del trait, compartidos con el
     * récipe de la pareja.
     *
     * @return array<string,string>
     */
    private function mensajes(): array
    {
        return ['fecha.date_format' => 'La fecha de emisión no es válida.'] + $this->mensajesDeLosItems();
    }

    /** La vuelta al editor del récipe con lo escrito (el `old()` del formulario). */
    private function volverAlEditor(Request $request, Consulta $consulta, ?int $numero): RedirectResponse
    {
        return redirect()
            ->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_RECIPES,
                'recipe' => $numero ?? 'nuevo',
            ])
            ->withInput($request->all());
    }

    /**
     * La vuelta después de guardar: **al editor de ese récipe** (O-28), no a la entrada de la sección —que
     * abre el primero—, para que el médico vea lo que acaba de guardar.
     */
    private function alEditor(Consulta $consulta, int $numero): RedirectResponse
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_RECIPES,
            'recipe' => $numero,
        ]);
    }

    /** "Récipe 2 creado: …", "Récipe 2 actualizado: agregado …, quitado …, guardado …": lo que pasó, con sus nombres. */
    private function resumen(ResultadoDeRecipe $resultado): string
    {
        if ($resultado->esNuevo) {
            return 'Récipe ' . $resultado->numero . ' creado: ' . $this->listar($resultado->agregados) . '.';
        }

        $partes = [];

        if ($resultado->agregados !== []) {
            $partes[] = 'agregado' . (count($resultado->agregados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->agregados);
        }
        if ($resultado->modificados !== []) {
            $partes[] = 'modificado' . (count($resultado->modificados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->modificados);
        }
        if ($resultado->quitados !== []) {
            $partes[] = 'quitado' . (count($resultado->quitados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->quitados);
        }
        if ($resultado->fechaCambiada) {
            $partes[] = 'cambiada la fecha de emisión';
        }

        return 'Récipe ' . $resultado->numero . ' actualizado: ' . implode(', ', $partes) . '.';
    }

    /** La consulta del contexto (404 si es de otro médico). */
    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }
}
