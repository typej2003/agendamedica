<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearPlantillaDeTexto;
use App\Actions\Catalogos\EditarPlantillaDeTexto;
use App\Actions\Catalogos\QuitarPlantillaDeTexto;
use App\Actions\Catalogos\ReglasDeLaPlantillaDeTexto;
use App\Clinica\Catalogos\PlantillasDeLaEspecialidad;
use App\Clinica\Catalogos\PlantillasDeTextoDelCatalogo;
use App\Clinica\Catalogos\Vistas\PlantillasDeTextoVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Plantillas de texto del médico** (WEB-8.4): `formato_print` del `reg_medico` del contexto —las plantillas de la evolución, del
 * informe médico, de las dietas…— en `/clinica/catalogos/plantillas-texto`: listado paginado con buscador sin tildes y filtro por
 * tipo, alta y edición en un diálogo y «quitar». La ventana «Plantillas» (`w_agregar_dietas`) del escritorio.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403) y **todo está acotado al `reg_medico` del
 * contexto**: la plantilla de otro médico responde 404. La regla no está acá: el alta es `CrearPlantillaDeTexto`, la edición
 * `EditarPlantillaDeTexto` y la baja `QuitarPlantillaDeTexto`, que **siempre** puede quitar (la plantilla se copia a la consulta, no
 * queda ninguna referencia a su fila).
 *
 * El filtro del listado se llama `filtro` y no `tipo`: `tipo` es el campo del formulario.
 */
class PlantillaDeTextoCatalogoController extends Controller
{
    public function __construct(private PlantillasDeTextoDelCatalogo $plantillas)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];
        // La especialidad del contexto decide qué tipos se ofrecen: en pediatría no están los ginecológicos (revisión del usuario,
        // 2026-10-18). Se resuelve en `PlantillasDeLaEspecialidad`, que delega la decisión en `AlcanceDeLaEspecialidad`.
        $especialidad = $contexto['specialty']->slug ?? null;

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $filtro = mb_substr(trim((string) $request->query('filtro', '')), 0, 10);
        $base = route('clinica.catalogos.plantillas-texto');
        $tipos = $this->plantillas->tipos($regMedico, $especialidad);

        // Un filtro de un tipo que el médico no tiene no acota nada y se deja como «todos» (un enlace viejo no da una lista vacía).
        if ($filtro !== '' && ! isset($tipos[$filtro])) {
            $filtro = '';
        }

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->plantillas->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.plantillas-texto', ['vm' => new PlantillasDeTextoVista(
            $this->plantillas->pagina($regMedico, $buscar, $filtro, (int) $request->query('page', 1), $base, array_filter(['buscar' => $buscar, 'filtro' => $filtro], fn ($valor) => $valor !== ''), $especialidad),
            $buscar,
            $filtro,
            $tipos,
            $this->plantillas->total($regMedico, $especialidad),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.plantillas-texto.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.plantillas-texto.quitar', $id),
            ],
            $request->boolean('nuevo'),
            PlantillasDeLaEspecialidad::nombres($especialidad),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request, CrearPlantillaDeTexto $crear, EditarPlantillaDeTexto $editar)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        // Los largos de las reglas se comprueban en la Action (con su aviso); acá sólo se acota lo que llega, con holgura, para no
        // procesar un cuerpo enorme.
        $datos = $request->validate([
            'id' => 'nullable|integer',
            'tipo' => 'nullable|string|max:10',
            'titulo' => 'nullable|string|max:1000',
            'descripcion' => 'nullable|string|max:' . (ReglasDeLaPlantillaDeTexto::LARGO_TEXTO * 2),
            'buscar' => 'nullable|string|max:100',
            'filtro' => 'nullable|string|max:10',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué plantilla se edita: vuelve a abrirla.',
            'tipo.max' => 'El tipo elegido no es válido.',
            'titulo.max' => 'El título es demasiado largo.',
            'descripcion.max' => 'El texto es demasiado largo.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'filtro.max' => 'El filtro no es válido.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ]);

        $fila = ($datos['id'] ?? null) !== null
            ? $this->plantillas->encontrar($regMedico, (int) $datos['id'])
            : null;

        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['filtro'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                $creada = $crear->ejecutar($regMedico, $datos);

                return redirect()
                    ->route('clinica.catalogos.plantillas-texto')
                    ->with('estado', 'Creaste la plantilla «' . trim((string) $creada->titulo) . '» (código ' . trim((string) $creada->codigo) . '). Ya se ofrece en la consulta.');
            }

            $resultado = $editar->ejecutar($fila, $datos);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.plantillas-texto', $vuelta);
        $nombre = trim((string) $resultado->fila->titulo);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $nombre . '» más recientemente y no se pisó: vuelve a abrirla y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $nombre . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $nombre . '». Lo que ya escribiste con ella en las consultas no cambia.');
    }

    /** Quitar una plantilla: siempre se puede (se copia a la consulta, no queda ninguna referencia); la pantalla pidió confirmación. */
    public function quitar(Request $request, int $id, QuitarPlantillaDeTexto $quitar)
    {
        $fila = $this->plantillas->encontrar((string) $request->attributes->get('contexto_clinico')['reg_medico'], $id);

        $vuelta = $this->vuelta(
            mb_substr(trim((string) $request->input('buscar', '')), 0, 100),
            mb_substr(trim((string) $request->input('filtro', '')), 0, 10),
            $request->input('page'),
        );

        $resultado = $quitar->ejecutar($fila);

        return redirect()->route('clinica.catalogos.plantillas-texto', $vuelta)
            ->with('estado', 'Quitaste la plantilla «' . $resultado->nombre . '». Lo que ya escribiste con ella en las consultas no cambia.');
    }

    /** El error se pinta bajo el campo del que habla; si no se sabe, bajo el título. */
    private function campoDelError(string $mensaje): string
    {
        if (str_contains($mensaje, 'tipo de plantilla')) {
            return 'tipo';
        }

        if (str_starts_with($mensaje, 'Escribe el texto') || str_starts_with($mensaje, 'El texto')) {
            return 'descripcion';
        }

        return 'titulo';
    }

    /** A dónde vuelve la lista: la misma búsqueda, el mismo filtro y la misma página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, ?string $filtro, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'filtro' => $filtro,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}
