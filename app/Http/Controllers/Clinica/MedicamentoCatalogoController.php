<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CamposDelMedicamento;
use App\Actions\Catalogos\CrearMedicamentoEnElCatalogo;
use App\Actions\Catalogos\EditarMedicamentoDelVademecum;
use App\Actions\Catalogos\QuitarMedicamentoDelCatalogo;
use App\Clinica\Catalogos\MedicamentosDelCatalogo;
use App\Clinica\Catalogos\Vistas\MedicamentosVista;
use App\Clinica\Consulta\DosisPediatrica;
use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Vademecum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Medicamentos del catálogo** (WEB-8.1a): el vademécum del médico del contexto —`vademecum`, no `vademecum_m` (trampa
 * 39)— con su listado paginado y buscador, el alta y la edición en un diálogo y el "quitar".
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría no administra catálogos clínicos y
 * recibe 403) y **todo está acotado al `reg_medico` del contexto**, que sale del contexto de trabajo y nunca del
 * formulario: un medicamento de otro médico responde 404.
 *
 * La regla de negocio no está acá: el alta es `CrearMedicamentoEnElCatalogo` (que usa `AgregarMedicamentoAlVademecum`,
 * la regla de WEB-5.1), la edición `EditarMedicamentoDelVademecum` y la baja `QuitarMedicamentoDelCatalogo`, que no
 * borra lo que está en uso.
 */
class MedicamentoCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private MedicamentosDelCatalogo $medicamentos)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.medicamentos');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->medicamentos->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.medicamentos', ['vm' => new MedicamentosVista(
            $this->medicamentos->pagina($regMedico, $buscar, (int) $request->query('page', 1), $base, $buscar === '' ? [] : ['buscar' => $buscar]),
            $buscar,
            $this->medicamentos->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.medicamentos.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.medicamentos.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(
        Request $request,
        CrearMedicamentoEnElCatalogo $crear,
        EditarMedicamentoDelVademecum $editar,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate($this->reglas(), $this->mensajes());

        $medicamento = ($datos['id'] ?? null) !== null
            ? $this->medicamentos->encontrar($regMedico, (int) $datos['id'])
            : null;

        $registros = $this->registrosDelMedico($contexto['medico']);
        $datos['sico'] = $request->boolean('sico');
        // «La dosis es por kilo» es una casilla (2026-10-21) que guarda `SI`, lo que lee `DosisPediatrica`. Destildarla quita el
        // `SI` pero no borra una nota vieja del escritorio en la dosificación. Sin la casilla en el envío, vale el texto como antes.
        if ($request->has('por_kilo')) {
            $previa = trim((string) ($medicamento->dosificacion ?? ''));
            $datos['dosificacion'] = $request->boolean('por_kilo')
                ? DosisPediatrica::POR_KILO
                : ($previa !== '' && mb_strtoupper($previa) !== DosisPediatrica::POR_KILO ? $previa : null);
        }
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            if ($medicamento === null) {
                [$creado, $nuevo] = $this->crear($crear, $regMedico, $registros, $datos);

                if (! $nuevo) {
                    return back()->withInput()->withErrors(['nombrecomercial' => $this->yaExiste($creado)]);
                }

                return redirect()
                    ->route('clinica.catalogos.medicamentos', ['buscar' => trim((string) $creado->nombrecomercial)])
                    ->with('estado', 'Creaste «' . $this->nombre($creado) . '» en tu vademécum (código ' . trim((string) $creado->codemedicina) . ').');
            }

            $resultado = $editar->ejecutar($medicamento, $registros, $datos, null, 'web');
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['nombrecomercial' => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.medicamentos', $vuelta);
        $nombre = $this->nombre($resultado->medicamento);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->etiquetas($resultado->bloqueadas) . ' de «' . $nombre
                . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $nombre . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $nombre . '»: cambió ' . $this->etiquetas($resultado->escritas) . '.');
    }

    /**
     * Quitar un medicamento. Si está en uso **no se borra** y el aviso dice dónde (en cuántos récipes, en cuál récipe
     * de la pareja y en qué tratamientos), para que el médico decida; si no, se borra (la pantalla pidió confirmación).
     */
    public function quitar(Request $request, Vademecum $medicamento, QuitarMedicamentoDelCatalogo $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $medicamento = $this->medicamentos->encontrar((string) $contexto['reg_medico'], (int) $medicamento->id);

        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));

        $resultado = $quitar->ejecutar($medicamento, null, 'web');
        $destino = redirect()->route('clinica.catalogos.medicamentos', $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Quítalo de ahí primero o déjalo en el vademécum.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» del vademécum.');
    }

    /**
     * El buscador del vademécum del médico, en JSON: lo usa el editor de tratamientos para agregar medicamentos.
     * Es la **misma** lectura que el buscador de la consulta (`RecipesDeLaConsulta::medicamentos`).
     */
    public function buscar(Request $request, RecipesDeLaConsulta $lectura): JsonResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $buscar = trim((string) $request->query('q', ''));

        return response()->json([
            'buscar' => $buscar,
            'medicamentos' => $lectura->medicamentos((string) $contexto['reg_medico'], $buscar)->values()->all(),
        ]);
    }

    /**
     * **El alta rápida** del editor de tratamientos: crea (o reusa, si ya hay uno igual) el medicamento que falta con
     * lo que el diálogo pide —nombre, principio activo, presentación e indicaciones— y lo devuelve en JSON para que el
     * editor lo agregue a la lista sin recargar (recargar perdería lo que hay sin guardar). Es la misma Action del alta
     * del ABM, así que el correlativo, el rastro y el «no duplicar» son los mismos.
     */
    public function crearRapido(Request $request, CrearMedicamentoEnElCatalogo $crear, RecipesDeLaConsulta $lectura): JsonResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate(
            array_intersect_key($this->reglas(), array_flip(['nombrecomercial', 'nombregenerico', 'presentacion', 'uso'])),
            $this->mensajes(),
        );

        try {
            [$medicamento, $creado] = $this->crear($crear, $regMedico, $this->registrosDelMedico($contexto['medico']), $datos);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'creado' => $creado,
            'medicamento' => $lectura->delVademecum($regMedico, $medicamento->codemedicina),
        ], $creado ? 201 : 200);
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** A dónde vuelve la lista: la misma búsqueda y página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** @return array{0: Vademecum, 1: bool} */
    private function crear(CrearMedicamentoEnElCatalogo $crear, string $regMedico, array $registros, array $datos): array
    {
        return $crear->ejecutar($regMedico, $registros, $datos, null, 'web');
    }

    /** @return array<string,string> */
    private function reglas(): array
    {
        return [
            'id' => 'nullable|integer',
            'nombrecomercial' => 'nullable|string|max:35',
            'nombregenerico' => 'nullable|string|max:35',
            'nombrecomercial1' => 'nullable|string|max:40',
            'nombrecomercial2' => 'nullable|string|max:40',
            'nombrecomercial3' => 'nullable|string|max:40',
            'presentacion' => 'nullable|string|max:50',
            'uso' => 'nullable|string|max:2000',
            'dosificacion' => 'nullable|string|max:2000',
            // La dosis por kilo del récipe pediátrico (2026-10-19): números opcionales, nunca negativos.
            'dosis' => 'nullable|numeric|min:0',
            'cada' => 'nullable|numeric|min:0',
            'durante' => 'nullable|numeric|min:0',
            'pvc' => 'nullable|numeric|min:0',
            'pvs' => 'nullable|numeric|min:0',
            'concentracion' => 'nullable|numeric|min:0',
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
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
            'id.integer' => 'No se pudo saber qué medicamento se edita: vuelve a abrirlo.',
            'nombrecomercial.max' => 'El nombre comercial no puede pasar de 35 caracteres.',
            'nombregenerico.max' => 'El principio activo no puede pasar de 35 caracteres.',
            'nombrecomercial1.max' => 'La segunda marca no puede pasar de 40 caracteres.',
            'nombrecomercial2.max' => 'La tercera marca no puede pasar de 40 caracteres.',
            'nombrecomercial3.max' => 'La cuarta marca no puede pasar de 40 caracteres.',
            'presentacion.max' => 'La presentación no puede pasar de 50 caracteres.',
            'uso.max' => 'Las indicaciones no pueden pasar de 2000 caracteres.',
            'dosificacion.max' => 'La dosificación no puede pasar de 2000 caracteres.',
            'dosis.numeric' => 'La dosis por kilo tiene que ser un número.',
            'dosis.min' => 'La dosis por kilo no puede ser negativa.',
            'cada.numeric' => 'Las veces al día tienen que ser un número.',
            'cada.min' => 'Las veces al día no pueden ser negativas.',
            'durante.numeric' => 'Los días de tratamiento tienen que ser un número.',
            'durante.min' => 'Los días de tratamiento no pueden ser negativos.',
            'pvc.numeric' => 'Los mg del envase tienen que ser un número.',
            'pvc.min' => 'Los mg del envase no pueden ser negativos.',
            'pvs.numeric' => 'Los ml del envase tienen que ser un número.',
            'pvs.min' => 'Los ml del envase no pueden ser negativos.',
            'concentracion.numeric' => 'Las gotas por mililitro tienen que ser un número.',
            'concentracion.min' => 'Las gotas por mililitro no pueden ser negativas.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ];
    }

    private function nombre(Vademecum $medicamento): string
    {
        $nombre = (new RecipesDeLaConsulta())->nombreDe($medicamento, null, trim((string) $medicamento->codemedicina));

        return $nombre;
    }

    private function yaExiste(Vademecum $existente): string
    {
        return 'Ya tienes «' . $this->nombre($existente) . '» en tu vademécum (código ' . trim((string) $existente->codemedicina)
            . '). Edítalo desde la lista o cambia la presentación.';
    }

    /** "el nombre comercial", "el nombre comercial y la presentación", "A, B y C". */
    private function etiquetas(array $columnas): string
    {
        $textos = array_map(fn ($columna) => CamposDelMedicamento::ETIQUETAS[$columna] ?? $columna, $columnas);

        if (count($textos) === 1) {
            return $textos[0];
        }

        $ultimo = array_pop($textos);

        return implode(', ', $textos) . ' y ' . $ultimo;
    }
}
