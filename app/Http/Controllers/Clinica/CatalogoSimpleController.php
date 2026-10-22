<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\ResultadoDeBaja;
use App\Actions\Catalogos\ResultadoDeEdicion;
use App\Clinica\Catalogos\CatalogoSimpleDelCatalogo;
use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use App\Clinica\Catalogos\Vistas\CatalogoSimpleVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Medico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Un catálogo de "código + texto" del médico** (WEB-8.1b): la base de los controladores de diagnósticos, motivos de
 * consulta y tipos de examen, que tienen exactamente la misma forma (listado paginado con buscador, alta y edición en un
 * diálogo y "quitar"). Cada hijo dice cuál es su catálogo (`definicion`) y con qué Actions se crea, se edita y se quita.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría no administra catálogos clínicos y recibe
 * 403) y **todo está acotado al `reg_medico` del contexto**, que sale del contexto de trabajo y nunca del formulario: una
 * fila de otro médico responde 404.
 *
 * La regla de negocio no está acá (guía de arquitectura de la web): el alta, la edición y la baja son Actions de
 * `App\Actions\Catalogos\`, y la baja **no borra lo que está en uso** y dice dónde.
 */
abstract class CatalogoSimpleController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(protected CatalogoSimpleDelCatalogo $catalogo)
    {
    }

    abstract protected function definicion(): DefinicionDeCatalogoSimple;

    /**
     * El alta, con la regla del catálogo.
     *
     * @param  array<int,string>  $registros
     * @return array{0: Model, 1: bool} la fila y si se creó ahora (`false` = ya existía una igual)
     */
    abstract protected function crear(Medico $medico, string $regMedico, array $registros, string $descripcion): array;

    /** @param  array<int,string>  $registros */
    abstract protected function editar(Model $fila, array $registros, string $descripcion): ResultadoDeEdicion;

    /** @param  array<int,string>  $registros */
    abstract protected function quitarFila(Model $fila, array $registros): ResultadoDeBaja;

    public function index(Request $request)
    {
        $def = $this->definicion();
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route($def->ruta());

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->catalogo->encontrar($def, $regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.simple', ['vm' => new CatalogoSimpleVista(
            $def,
            $this->catalogo->pagina($def, $regMedico, $buscar, (int) $request->query('page', 1), $base, $buscar === '' ? [] : ['buscar' => $buscar]),
            $buscar,
            $this->catalogo->total($def, $regMedico),
            $editando,
            $base,
            [
                'guardar' => route($def->ruta('guardar')),
                'quitar' => fn (int $id) => route($def->ruta('quitar'), $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request)
    {
        $def = $this->definicion();
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'id' => 'nullable|integer',
            // `nullable` y no `required`: el texto vacío no es un error de formulario sino la regla de negocio de la
            // Action, y el mensaje tiene que ser el suyo (igual que el alta rápida de la consulta).
            'descripcion' => 'nullable|string|max:300',
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué ' . $def->singular . ' se edita: vuelve a abrirlo.',
            'descripcion.max' => 'La descripción es demasiado larga.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ]);

        $fila = ($datos['id'] ?? null) !== null
            ? $this->catalogo->encontrar($def, $regMedico, (int) $datos['id'])
            : null;

        $registros = $this->registrosDelMedico($contexto['medico']);
        $texto = (string) ($datos['descripcion'] ?? '');
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                [$creada, $nueva] = $this->crear($contexto['medico'], $regMedico, $registros, $texto);
                $nombre = trim((string) $creada->getAttribute($def->columnaDelTexto));
                $codigo = trim((string) $creada->getAttribute($def->columnaDelCodigo));

                if (! $nueva) {
                    return back()->withInput()->withErrors(['descripcion' => 'Ya tienes «' . $nombre . '» en el catálogo (código ' . $codigo
                        . '). Edítalo desde la lista o escribe otro nombre.']);
                }

                return redirect()
                    ->route($def->ruta())
                    ->with('estado', 'Creaste «' . $nombre . '» en tus ' . $def->plural . ' (código ' . $codigo . ').');
            }

            $resultado = $this->editar($fila, $registros, $texto);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['descripcion' => $e->getMessage()]);
        }

        $destino = redirect()->route($def->ruta(), $vuelta);
        $nombre = trim((string) $resultado->fila->getAttribute($def->columnaDelTexto));

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $nombre . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $nombre . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $nombre . '».');
    }

    /**
     * Quitar una fila. Si está en uso **no se borra** y el aviso dice dónde (en cuántas consultas, o qué exámenes cuelgan
     * de ella), para que el médico decida; si no, se borra (la pantalla pidió confirmación).
     */
    public function quitar(Request $request, int $id)
    {
        $def = $this->definicion();
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->catalogo->encontrar($def, (string) $contexto['reg_medico'], $id);

        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));

        $resultado = $this->quitarFila($fila, $this->registrosDelMedico($contexto['medico']));
        $destino = redirect()->route($def->ruta(), $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Quítalo de ahí primero o déjalo en el catálogo.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» de tus ' . $def->plural . '.');
    }

    /** A dónde vuelve la lista: la misma búsqueda y página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}
