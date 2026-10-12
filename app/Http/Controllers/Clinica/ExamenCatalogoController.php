<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearExamenEnElCatalogo;
use App\Actions\Catalogos\EditarExamenDelCatalogo;
use App\Actions\Catalogos\QuitarExamenDelCatalogo;
use App\Clinica\Catalogos\ExamenesDelCatalogo;
use App\Clinica\Catalogos\Vistas\ExamenesVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Exámenes del catálogo** (WEB-8.1b): `examenes` del médico del contexto con su tipo (`tipos_examenes`), en
 * `/clinica/catalogos/examenes`: listado paginado con buscador sin tildes y filtro por tipo, alta y edición en un diálogo
 * y "quitar". El `w_agregar_examenes_estudios` del escritorio.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403) y **todo está acotado al
 * `reg_medico` del contexto**: un examen de otro médico responde 404. La regla no está acá: el alta es
 * `CrearExamenEnElCatalogo`, la edición `EditarExamenDelCatalogo` y la baja `QuitarExamenDelCatalogo`, que no borra lo que
 * alguna consulta tiene pedido y dice dónde.
 */
class ExamenCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ExamenesDelCatalogo $examenes)
    {
    }

    public function index(Request $request)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $tipo = mb_substr(trim((string) $request->query('tipo', '')), 0, 10);
        $base = route('clinica.catalogos.examenes');
        $tipos = $this->examenes->tipos($regMedico);

        // Un filtro de un tipo que no existe no acota nada y se deja como «todos» (un enlace viejo no da una lista vacía).
        if ($tipo !== '' && $tipo !== ExamenesDelCatalogo::SIN_TIPO && ! isset($tipos[$tipo])) {
            $tipo = '';
        }

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->examenes->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.examenes', ['vm' => new ExamenesVista(
            $this->examenes->pagina($regMedico, $buscar, $tipo, (int) $request->query('page', 1), $base, array_filter(['buscar' => $buscar, 'tipo' => $tipo], fn ($valor) => $valor !== '')),
            $buscar,
            $tipo,
            $tipos,
            $this->examenes->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.examenes.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.examenes.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request, CrearExamenEnElCatalogo $crear, EditarExamenDelCatalogo $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'id' => 'nullable|integer',
            'examen' => 'nullable|string|max:300',
            'codetipo' => 'nullable|string|max:10',
            'buscar' => 'nullable|string|max:100',
            'tipo' => 'nullable|string|max:10',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué examen se edita: vuelve a abrirlo.',
            'examen.max' => 'El nombre del examen es demasiado largo.',
            'codetipo.max' => 'El tipo elegido no es válido.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'tipo.max' => 'El filtro no es válido.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ]);

        $fila = ($datos['id'] ?? null) !== null
            ? $this->examenes->encontrar($regMedico, (int) $datos['id'])
            : null;

        $registros = $this->registrosDelMedico($contexto['medico']);
        $nombre = (string) ($datos['examen'] ?? '');
        $codetipo = $datos['codetipo'] ?? null;
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['tipo'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                [$creado, $nuevo] = $crear->ejecutar($regMedico, $registros, $nombre, $codetipo);

                if (! $nuevo) {
                    return back()->withInput()->withErrors(['examen' => 'Ya tienes «' . trim((string) $creado->examen) . '» con ese tipo en el catálogo (código '
                        . trim((string) $creado->codeexamen) . '). Edítalo desde la lista o cambia el nombre o el tipo.']);
                }

                return redirect()
                    ->route('clinica.catalogos.examenes', ['buscar' => trim((string) $creado->examen)])
                    ->with('estado', 'Creaste «' . trim((string) $creado->examen) . '» en tus exámenes (código ' . trim((string) $creado->codeexamen) . ').');
            }

            $resultado = $editar->ejecutar($fila, $registros, $nombre, $codetipo);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.examenes', $vuelta);
        $texto = trim((string) $resultado->fila->examen);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $texto . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $texto . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $texto . '».');
    }

    /**
     * Quitar un examen. Si alguna consulta lo tiene pedido **no se borra** y el aviso dice en cuántas; si no, se borra (la
     * pantalla pidió confirmación).
     */
    public function quitar(Request $request, int $id, QuitarExamenDelCatalogo $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->examenes->encontrar((string) $contexto['reg_medico'], $id);

        $vuelta = $this->vuelta(
            mb_substr(trim((string) $request->input('buscar', '')), 0, 100),
            mb_substr(trim((string) $request->input('tipo', '')), 0, 10),
            $request->input('page'),
        );

        $resultado = $quitar->ejecutar($fila, $this->registrosDelMedico($contexto['medico']));
        $destino = redirect()->route('clinica.catalogos.examenes', $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Quítalo de ahí primero o déjalo en el catálogo.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» de tus exámenes.');
    }

    /** El error del tipo se pinta bajo el `<select>` del tipo y el resto bajo el nombre. */
    private function campoDelError(string $mensaje): string
    {
        return str_contains($mensaje, 'tipo de la lista') ? 'codetipo' : 'examen';
    }

    /** A dónde vuelve la lista: la misma búsqueda, el mismo tipo y la misma página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, ?string $tipo, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'tipo' => $tipo,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}
