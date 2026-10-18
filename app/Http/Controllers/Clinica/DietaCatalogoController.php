<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\GuardarDietaDelCatalogo;
use App\Actions\Catalogos\QuitarDietaDelCatalogo;
use App\Clinica\Catalogos\DietasDelCatalogoAbm;
use App\Clinica\Catalogos\Vistas\DietasCatalogoVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Dietas del médico** (WEB-12.4b): `dietas` del `reg_medico` del contexto en `/clinica/catalogos/dietas`: listado paginado con buscador sin tildes,
 * alta y edición en un diálogo y «quitar». Es el catálogo que alimenta la pestaña *Alimentación* de la consulta pediátrica (`Consulta\DietasDelCatalogo`).
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403) y todo está acotado al `reg_medico` del contexto: la dieta de
 * otro médico responde 404. La regla vive en `GuardarDietaDelCatalogo` y `QuitarDietaDelCatalogo`, que **siempre** puede quitar (la dieta se copia a la
 * consulta, no queda ninguna referencia a su fila).
 */
class DietaCatalogoController extends Controller
{
    public function __construct(private DietasDelCatalogoAbm $dietas)
    {
    }

    public function index(Request $request)
    {
        $regMedico = $this->regMedico($request);
        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.dietas');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->dietas->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.dietas', ['vm' => new DietasCatalogoVista(
            $this->dietas->pagina($regMedico, $buscar, (int) $request->query('page', 1), $base, array_filter(['buscar' => $buscar], fn ($valor) => $valor !== '')),
            $buscar,
            $this->dietas->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.dietas.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.dietas.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`). */
    public function guardar(Request $request, GuardarDietaDelCatalogo $accion)
    {
        $regMedico = $this->regMedico($request);

        // Los largos de las reglas se comprueban en la Action (con su aviso); acá sólo se acota lo que llega, con holgura.
        $datos = $request->validate([
            'id' => 'nullable|integer',
            'dietas' => 'nullable|string|max:1000',
            'descripcion' => 'nullable|string|max:' . (GuardarDietaDelCatalogo::LARGO_TEXTO * 2),
            'calorias' => 'nullable|string|max:20',
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué dieta se edita: vuelve a abrirla.',
            'dietas.max' => 'El título es demasiado largo.',
            'descripcion.max' => 'El texto es demasiado largo.',
            'calorias.max' => 'Las calorías no son válidas.',
        ]);

        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            $resultado = $accion->ejecutar($regMedico, $datos, isset($datos['id']) ? (int) $datos['id'] : null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $nombre = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['dietas'] ?? ''))));

        if ($resultado->creado) {
            return redirect()->route('clinica.catalogos.dietas')->with('estado', 'Creaste la dieta «' . $nombre . '». Ya se ofrece en la pestaña Alimentación de la consulta.');
        }

        $destino = redirect()->route('clinica.catalogos.dietas', $vuelta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $nombre . '» más recientemente y no se pisó: vuelve a abrirla y revisa los cambios.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? '«' . $nombre . '» no tenía cambios.'
            : 'Guardaste «' . $nombre . '». Lo que ya escribiste con ella en las consultas no cambia.');
    }

    /** Quitar una dieta: siempre se puede (se copia a la consulta, no queda ninguna referencia); la pantalla pidió confirmación. */
    public function quitar(Request $request, int $id, QuitarDietaDelCatalogo $accion)
    {
        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));

        try {
            $nombre = $accion->ejecutar($this->regMedico($request), $id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('clinica.catalogos.dietas', $vuelta)->with('error', $e->getMessage());
        }

        return redirect()->route('clinica.catalogos.dietas', $vuelta)
            ->with('estado', 'Quitaste la dieta «' . $nombre . '». Lo que ya escribiste con ella en las consultas no cambia.');
    }

    private function regMedico(Request $request): string
    {
        return (string) $request->attributes->get('contexto_clinico')['reg_medico'];
    }

    /** El error se pinta bajo el campo del que habla; si no se sabe, bajo el título. */
    private function campoDelError(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'calorías') => 'calorias',
            str_contains($mensaje, 'texto') => 'descripcion',
            default => 'dietas',
        };
    }

    /** A dónde vuelve la lista: la misma búsqueda y la misma página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter(['buscar' => $buscar, 'page' => (int) $pagina > 1 ? (int) $pagina : null], fn ($valor) => $valor !== null && $valor !== '');
    }
}
