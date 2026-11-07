<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearSeguroDeEmpresa;
use App\Actions\Catalogos\EditarSeguroDeEmpresa;
use App\Actions\Catalogos\QuitarSeguroDeEmpresa;
use App\Actions\Catalogos\ReglasDelSeguroDeEmpresa;
use App\Clinica\Catalogos\SegurosDelCatalogo;
use App\Clinica\Catalogos\Vistas\SegurosVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Seguros / empresas del consultorio** (O-62, `/clinica/catalogos/seguros`): el catálogo `seg_emp` del médico del
 * contexto, con listado paginado y buscador sin tildes, alta y edición en un diálogo (nombre obligatorio; RIF,
 * teléfono y dirección opcionales) y «quitar».
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`): la secretaría recibe 403 y un seguro de otro
 * consultorio responde 404, porque todo se acota al `reg_medico` del contexto.
 *
 * Lo que se carga acá es lo que ofrece el `<select>` «Seguro / empresa» del alta del paciente **de ese consultorio**
 * (`App\Clinica\Pacientes\CatalogoDeSeguros`), que es la observación que originó la pantalla: el alta del paciente ya
 * ofrecía el campo y el médico no tenía dónde cargar el catálogo.
 *
 * La regla no está acá: el nombre obligatorio en mayúsculas, el ancho de cada columna y el correlativo de dos dígitos
 * son de `ReglasDelSeguroDeEmpresa`; el alta, la edición y la baja son las tres Actions del catálogo. La baja **no
 * borra** un seguro que algún paciente de ese consultorio tenga como suyo (`UsoDeUnItemDelCatalogo::seguro`).
 */
class SeguroCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private SegurosDelCatalogo $seguros)
    {
    }

    public function index(Request $request)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.seguros');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->seguros->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.seguros', ['vm' => new SegurosVista(
            $this->seguros->pagina($regMedico, $buscar, (int) $request->query('page', 1), $base, $buscar !== '' ? ['buscar' => $buscar] : []),
            $buscar,
            $this->seguros->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.seguros.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.seguros.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request, CrearSeguroDeEmpresa $crear, EditarSeguroDeEmpresa $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate($this->reglas(), $this->mensajes());

        $fila = ($datos['id'] ?? null) !== null ? $this->seguros->encontrar($regMedico, (int) $datos['id']) : null;
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                $creado = $crear->ejecutar($regMedico, $datos);

                return redirect()
                    ->route('clinica.catalogos.seguros')
                    ->with('estado', 'Creaste «' . $this->nombre($creado) . '» (código ' . trim((string) $creado->codesegemp) . ').');
            }

            $resultado = $editar->ejecutar($fila, $datos);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.seguros', $vuelta);
        $nombre = $this->nombre($resultado->fila);

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
     * Quitar un seguro: si algún paciente de ese consultorio lo tiene **no se borra** y el aviso dice cuántos y quiénes;
     * si no, se borra (la pantalla pidió confirmación).
     */
    public function quitar(Request $request, int $id, QuitarSeguroDeEmpresa $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->seguros->encontrar((string) $contexto['reg_medico'], $id);

        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));
        $resultado = $quitar->ejecutar($fila, $this->registrosDelMedico($contexto['medico']));
        $destino = redirect()->route('clinica.catalogos.seguros', $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Cambia el seguro en la ficha del paciente o déjalo en el catálogo.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» del catálogo.');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    private function nombre($fila): string
    {
        $nombre = trim((string) $fila->nombre);

        return $nombre !== '' ? $nombre : 'seguro ' . trim((string) $fila->codesegemp);
    }

    /** Dónde se pinta el error de la Action: bajo el campo del que habla; si no se sabe, bajo el nombre. */
    private function campoDelError(string $mensaje): string
    {
        if (str_starts_with($mensaje, 'Ya tienes')) {
            return 'nombre';
        }

        foreach (['rif' => 'rif', 'dirección' => 'direccion', 'teléfono' => 'telef'] as $palabra => $campo) {
            if (str_contains(mb_strtolower($mensaje), $palabra)) {
                return $campo;
            }
        }

        return 'nombre';
    }

    /** "el RIF", "el RIF y el teléfono", "el nombre, la dirección y el teléfono". */
    private function etiquetas(array $columnas): string
    {
        $textos = array_map(fn ($columna) => ReglasDelSeguroDeEmpresa::ETIQUETAS[$columna] ?? $columna, $columnas);

        if (count($textos) === 1) {
            return $textos[0];
        }

        $ultimo = array_pop($textos);

        return implode(', ', $textos) . ' y ' . $ultimo;
    }

    /** A dónde vuelve la lista: la misma búsqueda y página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** @return array<string,string> */
    private function reglas(): array
    {
        return [
            'id' => 'nullable|integer',
            'nombre' => 'nullable|string|max:' . ReglasDelSeguroDeEmpresa::LARGO_NOMBRE,
            'rif' => 'nullable|string|max:' . ReglasDelSeguroDeEmpresa::LARGO_RIF,
            'direccion' => 'nullable|string|max:' . ReglasDelSeguroDeEmpresa::LARGO_DIRECCION,
            'telef' => 'nullable|string|max:' . ReglasDelSeguroDeEmpresa::LARGO_TELEFONO,
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * Un aviso por regla: el `locale` de la app es `en` y la validación sale en inglés si no se le da su texto
     * (trampa 38).
     *
     * @return array<string,string>
     */
    private function mensajes(): array
    {
        return [
            'id.integer' => 'No se pudo saber qué seguro se edita: vuelve a abrirlo.',
            'nombre.max' => 'El nombre admite hasta ' . ReglasDelSeguroDeEmpresa::LARGO_NOMBRE . ' caracteres.',
            'rif.max' => 'El RIF admite hasta ' . ReglasDelSeguroDeEmpresa::LARGO_RIF . ' caracteres.',
            'direccion.max' => 'La dirección admite hasta ' . ReglasDelSeguroDeEmpresa::LARGO_DIRECCION . ' caracteres.',
            'telef.max' => 'El teléfono admite hasta ' . ReglasDelSeguroDeEmpresa::LARGO_TELEFONO . ' caracteres.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ];
    }
}
