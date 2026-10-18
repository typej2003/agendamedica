<?php

namespace App\Http\Livewire\Admin;

use App\Actions\Catalogos\GuardarFilaDeCatalogoInicial;
use App\Actions\Catalogos\GuardarLineaDeTratamientoInicial;
use App\Actions\Catalogos\QuitarFilaDeCatalogoInicial;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales as Registro;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Catálogos iniciales** (WEB-12.8): el ABM, desde el panel, de las filas sin dueño (`reg_medico` NULL) de los catálogos que cada médico recibe como punto de partida
 * con «Cargar el catálogo inicial». Es **un solo componente** para todos: el formulario, la lista y las reglas salen de `CatalogosIniciales` (el registro) y de las
 * Actions `GuardarFilaDeCatalogoInicial` y `QuitarFilaDeCatalogoInicial`; el detalle de un tratamiento es `GuardarLineaDeTratamientoInicial`. Un chip por catálogo
 * (`_catalogo-inicial-chips`). Las vacunas y la referencia de crecimiento tienen su propia pantalla.
 *
 * Como las demás pantallas del panel, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire (Root o Administrador).
 */
class CatalogosIniciales extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** El slug del catálogo (`diagnosticos`, `examenes`…). */
    public $catalogo = '';

    public $search = '';

    /** Ventana abierta: `fila` | `borrar` | `lineas` | null */
    public $modal = null;

    public $filaId = null;

    /** @var array<string,mixed> los campos del formulario de la fila (`columna => valor`) */
    public $f = [];

    /** La línea que se edita en el detalle de un tratamiento (`null` = alta) y sus campos. */
    public $lineaId = null;
    public $l = ['codemedicina' => '', 'cantidad' => '1', 'indicaciones' => ''];

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount(string $catalogo)
    {
        $this->autorizar();
        abort_unless(Registro::existe($catalogo), 404);
        $this->catalogo = $catalogo;
        $this->f = $this->vacio();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $def = Registro::de($this->catalogo);
        $busqueda = trim($this->search);

        $filas = DB::table($def['tabla'])->whereNull('reg_medico')
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w->where($def['etiqueta'], 'like', "%{$busqueda}%")->orWhere($def['codigo'], 'like', "%{$busqueda}%")))
            ->orderBy($def['etiqueta'])->orderBy('id')->paginate(15);

        $lineas = collect();
        $tratamiento = null;
        if ($this->modal === 'lineas') {
            $tratamiento = DB::table('recipe_grupo')->whereNull('reg_medico')->find($this->filaId);
            $lineas = $tratamiento
                ? DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $tratamiento->codigo)->orderBy('orden')->orderBy('id')->get()
                : collect();
        }

        return view('livewire.admin.catalogos-iniciales', [
            'def' => $def,
            'filas' => $filas,
            'detalles' => $filas->getCollection()->mapWithKeys(fn ($fila) => [$fila->id => $this->detalle($def, $fila)]),
            'total' => DB::table($def['tabla'])->whereNull('reg_medico')->count(),
            'opciones' => $this->opciones($def),
            'tratamiento' => $tratamiento,
            'lineas' => $lineas,
            'medicamentos' => $this->modal === 'lineas' ? DB::table('vademecum')->whereNull('reg_medico')->orderBy('nombrecomercial')->get(['codemedicina', 'nombrecomercial', 'presentacion']) : collect(),
        ]);
    }

    public function abrir($id = null)
    {
        $this->resetErrorBag();
        $this->f = $this->vacio();
        $this->filaId = null;

        if ($id) {
            $def = Registro::de($this->catalogo);
            $fila = DB::table($def['tabla'])->whereNull('reg_medico')->where('id', (int) $id)->first();
            abort_if($fila === null, 404);
            $this->filaId = $fila->id;
            foreach (array_keys($def['campos']) as $columna) {
                $valor = $fila->{$columna} ?? null;
                $this->f[$columna] = $def['campos'][$columna][1] === 'marca' ? (bool) $valor : ($valor === null ? '' : (string) $valor);
            }
        }

        $this->modal = 'fila';
    }

    public function guardar()
    {
        $this->resetErrorBag();

        try {
            app(GuardarFilaDeCatalogoInicial::class)->ejecutar($this->catalogo, $this->f, $this->filaId ? (int) $this->filaId : null);
        } catch (InvalidArgumentException $e) {
            $this->addError('f.formulario', $e->getMessage());

            return;
        }

        session()->flash('message', $this->filaId ? 'Fila actualizada.' : 'Fila agregada al catálogo inicial.');
        $this->cerrar();
    }

    public function abrirBorrar($id)
    {
        $this->filaId = (int) $id;
        $this->resetErrorBag();
        $this->modal = 'borrar';
    }

    public function borrar()
    {
        try {
            $nombre = app(QuitarFilaDeCatalogoInicial::class)->ejecutar($this->catalogo, (int) $this->filaId);
            session()->flash('message', '«' . $nombre . '» salió del catálogo inicial. Los consultorios que ya la copiaron la conservan.');
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->cerrar();
    }

    // ------------------------------------------------------------------ Líneas de un tratamiento

    public function abrirLineas($id)
    {
        abort_unless($this->catalogo === 'tratamientos', 404);
        abort_if(DB::table('recipe_grupo')->whereNull('reg_medico')->where('id', (int) $id)->doesntExist(), 404);
        $this->filaId = (int) $id;
        $this->nuevaLinea();
        $this->modal = 'lineas';
    }

    public function editarLinea($id)
    {
        $linea = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('id', (int) $id)->first();
        abort_if($linea === null, 404);
        $this->lineaId = $linea->id;
        $this->l = ['codemedicina' => (string) $linea->codemedicina, 'cantidad' => (string) $linea->cantidad, 'indicaciones' => (string) $linea->indicaciones];
        $this->resetErrorBag();
    }

    public function nuevaLinea()
    {
        $this->lineaId = null;
        $this->l = ['codemedicina' => '', 'cantidad' => '1', 'indicaciones' => ''];
        $this->resetErrorBag();
    }

    public function guardarLinea()
    {
        $tratamiento = DB::table('recipe_grupo')->whereNull('reg_medico')->find($this->filaId);
        abort_if($tratamiento === null, 404);

        try {
            app(GuardarLineaDeTratamientoInicial::class)->ejecutar((string) $tratamiento->codigo, $this->l, $this->lineaId ? (int) $this->lineaId : null);
        } catch (InvalidArgumentException $e) {
            $this->addError('l.formulario', $e->getMessage());

            return;
        }

        session()->flash('message', 'Línea guardada.');
        $this->nuevaLinea();
    }

    public function quitarLinea($id)
    {
        try {
            app(GuardarLineaDeTratamientoInicial::class)->quitar((int) $id);
            session()->flash('message', 'Línea quitada.');
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
        $this->nuevaLinea();
    }

    public function cerrar()
    {
        $this->modal = null;
        $this->filaId = null;
        $this->lineaId = null;
        $this->resetErrorBag();
    }

    // ------------------------------------------------------------------ Adentro

    /** @return array<string,mixed> */
    private function vacio(): array
    {
        $campos = [];
        foreach (Registro::de($this->catalogo)['campos'] as $columna => $campo) {
            $campos[$columna] = $campo[1] === 'marca' ? false : '';
        }

        return $campos;
    }

    /** Lo que la lista dice de una fila, además de su nombre. */
    private function detalle(array $def, object $fila): string
    {
        switch ($this->catalogo) {
            case 'examenes':
                return (string) DB::table('tipos_examenes')->whereNull('reg_medico')->where('codetipo', $fila->codetipo)->value('tipo');
            case 'plantillas-texto':
                return TiposDePlantillaDeTexto::nombre($fila->tipo) . ' · ' . Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion)), 70);
            case 'motivos-cita':
                return $fila->precio === null ? 'Sin precio' : number_format((float) $fila->precio, 2, ',', '.');
            case 'tratamientos':
                $n = DB::table('recipe_grupo_detalle')->whereNull('reg_medico')->where('codigo', $fila->codigo)->count();

                return $n . ($n === 1 ? ' medicamento' : ' medicamentos');
            case 'medicamentos':
                return trim(implode(' · ', array_filter([trim((string) $fila->presentacion), trim((string) $fila->nombregenerico)])));
        }

        return '';
    }

    /** @return array<string,array<string,string>> las opciones de los campos `ref` y `tipo` */
    private function opciones(array $def): array
    {
        $opciones = [];

        foreach ($def['campos'] as $columna => $campo) {
            if ($campo[1] === 'ref') {
                $destino = Registro::de($def['refs'][$columna]);
                $opciones[$columna] = DB::table($destino['tabla'])->whereNull('reg_medico')->orderBy($destino['etiqueta'])
                    ->pluck($destino['etiqueta'], $destino['codigo'])->map(fn ($v) => trim((string) $v))->all();
            }
            if ($campo[1] === 'tipo') {
                $opciones[$columna] = TiposDePlantillaDeTexto::nombres();
            }
        }

        return $opciones;
    }
}
