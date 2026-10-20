<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\GuardarTratamiento;
use App\Actions\Catalogos\QuitarTratamiento;
use App\Actions\Catalogos\ResultadoDeTratamiento;
use App\Clinica\Catalogos\TratamientosDelCatalogo;
use App\Clinica\Catalogos\Vistas\EditorDeTratamientoVista;
use App\Clinica\Catalogos\Vistas\TratamientosVista;
use App\Clinica\Consulta\Vistas\SeccionDeRecipesVista;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Tratamientos del catálogo** (WEB-8.1a): las plantillas de récipe del médico del contexto (`recipe_grupo` con sus
 * medicamentos de `recipe_grupo_detalle`). La lista y el "quitar" son una pantalla; alta y edición son el **editor**,
 * que es el del récipe (los mismos parciales y el mismo `consulta-recipes.js`) con el nombre del tratamiento y el
 * orden de sus medicamentos.
 *
 * Es del **médico** (la secretaría recibe 403) y todo se acota al `reg_medico` del contexto: un tratamiento de otro
 * médico responde 404. La regla de negocio es `GuardarTratamiento` / `QuitarTratamiento`; acá se valida el
 * formulario, se comprueban los medicamentos contra el vademécum del médico (`RecipesDeLaConsulta`, la misma regla
 * de los récipes: no hay texto libre) y se cuenta qué pasó.
 */
class TratamientoCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private TratamientosDelCatalogo $tratamientos)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];
        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);

        return view('clinica.catalogos.tratamientos', ['vm' => new TratamientosVista(
            $this->tratamientos->listar($regMedico, $buscar),
            $buscar,
            $this->tratamientos->total($regMedico),
            [
                'base' => route('clinica.catalogos.tratamientos'),
                'nuevo' => route('clinica.catalogos.tratamientos.nuevo'),
                'editar' => fn (int $id) => route('clinica.catalogos.tratamientos.editar', $id),
                'quitar' => fn (int $id) => route('clinica.catalogos.tratamientos.quitar', $id),
            ],
        )]);
    }

    public function nuevo(Request $request)
    {
        return $this->editor($request, null);
    }

    public function editar(Request $request, RecipeGrupo $tratamiento)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->editor($request, $this->tratamientos->encontrar((string) $contexto['reg_medico'], (int) $tratamiento->id));
    }

    /** Guardar un tratamiento **nuevo**: le toca el código que sigue. */
    public function crear(Request $request, RecipesDeLaConsulta $lectura, GuardarTratamiento $guardar)
    {
        return $this->guardarTratamiento($request, null, $lectura, $guardar);
    }

    /** Guardar los cambios de un tratamiento que **ya existe**, en el lugar. */
    public function guardar(Request $request, RecipeGrupo $tratamiento, RecipesDeLaConsulta $lectura, GuardarTratamiento $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $tratamiento = $this->tratamientos->encontrar((string) $contexto['reg_medico'], (int) $tratamiento->id);

        return $this->guardarTratamiento($request, $tratamiento, $lectura, $guardar);
    }

    /**
     * Quitar un tratamiento con todos sus medicamentos. **Siempre se puede**: es una plantilla que se copia al
     * récipe, no hay uso que proteger (los récipes ya hechos no lo referencian).
     */
    public function quitar(Request $request, RecipeGrupo $tratamiento, QuitarTratamiento $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $tratamiento = $this->tratamientos->encontrar((string) $contexto['reg_medico'], (int) $tratamiento->id);

        $resultado = $quitar->ejecutar($tratamiento, null, 'web');

        return redirect()
            ->route('clinica.catalogos.tratamientos')
            ->with('estado', 'Quitaste el tratamiento «' . $resultado->nombre . '» (' . $resultado->medicamentos
                . ($resultado->medicamentos === 1 ? ' medicamento' : ' medicamentos') . ').');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    private function editor(Request $request, ?RecipeGrupo $tratamiento)
    {
        return view('clinica.catalogos.tratamiento-editor', ['vm' => new EditorDeTratamientoVista(
            $tratamiento,
            $tratamiento === null ? null : $this->tratamientos->itemsDe($tratamiento),
            [
                'guardar' => $tratamiento === null
                    ? route('clinica.catalogos.tratamientos.crear')
                    : route('clinica.catalogos.tratamientos.guardar', $tratamiento->id),
                'lista' => route('clinica.catalogos.tratamientos'),
                'medicamentos' => route('clinica.catalogos.medicamentos.buscar'),
                'crearMedicamento' => route('clinica.catalogos.medicamentos.rapido'),
            ],
            (array) old(),
        )]);
    }

    /** Valida el formulario del editor, comprueba los medicamentos y llama a la Action. */
    private function guardarTratamiento(
        Request $request,
        ?RecipeGrupo $tratamiento,
        RecipesDeLaConsulta $lectura,
        GuardarTratamiento $guardar,
    ): RedirectResponse {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate($this->reglas(), $this->mensajes());
        $items = (array) ($datos['items'] ?? []);

        // Lo que el tratamiento ya tiene se conserva aunque el vademécum ya no lo traiga (el legado no impide
        // borrarlo); lo que es nuevo tiene que estar en el vademécum o en otro tratamiento del médico.
        $actuales = [];
        if ($tratamiento !== null) {
            foreach (RecipeGrupoDetalle::where('reg_medico', $regMedico)->where('codigo', trim((string) $tratamiento->codigo))->get() as $fila) {
                $actuales[trim((string) $fila->codemedicina)] = trim((string) $fila->descripcion) === '' ? null : trim((string) $fila->descripcion);
            }
        }

        $resolucion = $lectura->resolverItemsDelMedico($regMedico, $actuales, $items);

        if ($resolucion['desconocidos'] !== []) {
            return $this->volverAlEditor($request, $tratamiento)->withErrors([
                'items' => 'Hay medicamentos que no están en tu vademécum: '
                    . implode(', ', $resolucion['desconocidos']) . '. Quítalos o créalos en Medicamentos.',
            ]);
        }

        try {
            $resultado = $guardar->ejecutar(
                $regMedico,
                $this->registrosDelMedico($contexto['medico']),
                $tratamiento,
                (string) $datos['tratamiento'],
                $resolucion['items'],
                null,
                'web',
            );
        } catch (InvalidArgumentException $e) {
            return $this->volverAlEditor($request, $tratamiento)->withErrors(['tratamiento' => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.tratamientos');
        $nombre = trim((string) $resultado->tratamiento->tratamiento);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados) . ' de «' . $nombre
                . '» más recientemente y no se pisó: vuelve a abrir el tratamiento y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'El tratamiento «' . $nombre . '» no tenía cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado, $nombre));
    }

    /** @return array<string,mixed> */
    private function reglas(): array
    {
        return [
            'tratamiento' => 'required|string|max:' . GuardarTratamiento::LARGO_NOMBRE,
            'items' => 'nullable|array|max:60',
            'items.*.codemedicina' => 'required|string|max:8',
            'items.*.cantidad' => 'nullable|integer|min:1|max:' . SeccionDeRecipesVista::CANTIDAD_MAXIMA,
            'items.*.indicaciones' => 'nullable|string|max:' . SeccionDeRecipesVista::LARGO_INDICACIONES,
        ];
    }

    /**
     * Un aviso por regla: el `locale` de la app es `en` y la validación sale en inglés si no se le da su texto
     * (trampa 38 de `TRAMPAS.md`).
     *
     * @return array<string,string>
     */
    private function mensajes(): array
    {
        return [
            'tratamiento.required' => 'Ponle un nombre al tratamiento.',
            'tratamiento.max' => 'El nombre del tratamiento no puede pasar de ' . GuardarTratamiento::LARGO_NOMBRE . ' caracteres.',
            'items.array' => 'Los medicamentos del tratamiento no llegaron bien: vuelve a abrir el editor.',
            'items.max' => 'Un tratamiento no puede llevar más de 60 medicamentos.',
            'items.*.codemedicina.required' => 'A una fila del tratamiento le falta el medicamento.',
            'items.*.codemedicina.max' => 'El código de un medicamento es demasiado largo.',
            'items.*.cantidad.integer' => 'La cantidad de cada medicamento tiene que ser un número entero.',
            'items.*.cantidad.min' => 'La cantidad mínima de un medicamento es 1.',
            'items.*.cantidad.max' => 'La cantidad de un medicamento no puede pasar de ' . SeccionDeRecipesVista::CANTIDAD_MAXIMA . '.',
            'items.*.indicaciones.max' => 'Las indicaciones de un medicamento no pueden pasar de '
                . SeccionDeRecipesVista::LARGO_INDICACIONES . ' caracteres.',
        ];
    }

    /** La vuelta al editor con lo escrito (el `old()` del formulario). */
    private function volverAlEditor(Request $request, ?RecipeGrupo $tratamiento): RedirectResponse
    {
        return redirect()
            ->route($tratamiento === null ? 'clinica.catalogos.tratamientos.nuevo' : 'clinica.catalogos.tratamientos.editar', $tratamiento === null ? [] : [$tratamiento->id])
            ->withInput($request->all());
    }

    /** "Tratamiento «X» creado: …", "…actualizado: agregado …, quitado …": lo que pasó, con sus nombres. */
    private function resumen(ResultadoDeTratamiento $resultado, string $nombre): string
    {
        if ($resultado->esNuevo) {
            $cuantos = count($resultado->agregados);

            return 'Creaste el tratamiento «' . $nombre . '» (código ' . trim((string) $resultado->tratamiento->codigo) . ') con '
                . ($cuantos === 0 ? 'la lista vacía' : $cuantos . ($cuantos === 1 ? ' medicamento' : ' medicamentos')) . '.';
        }

        $partes = [];

        if ($resultado->nombreCambiado) {
            $partes[] = 'cambiado el nombre';
        }
        if ($resultado->agregados !== []) {
            $partes[] = 'agregado' . (count($resultado->agregados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->agregados);
        }
        if ($resultado->modificados !== []) {
            $partes[] = 'modificado' . (count($resultado->modificados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->modificados);
        }
        if ($resultado->quitados !== []) {
            $partes[] = 'quitado' . (count($resultado->quitados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->quitados);
        }
        if ($resultado->reordenado) {
            $partes[] = 'cambiado el orden';
        }

        return 'Tratamiento «' . $nombre . '» actualizado: ' . implode(', ', $partes) . '.';
    }

    /** «A», «A» y «B», «A», «B» y «C». */
    private function listar(array $nombres): string
    {
        $etiquetas = array_map(fn ($nombre) => $nombre === 'el nombre' ? $nombre : '«' . $nombre . '»', $nombres);

        if (count($etiquetas) === 1) {
            return $etiquetas[0];
        }

        $ultimo = array_pop($etiquetas);

        return implode(', ', $etiquetas) . ' y ' . $ultimo;
    }
}
