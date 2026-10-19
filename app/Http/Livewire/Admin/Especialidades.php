<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\Specialty;
use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "Especialidades" del panel: con qué especialidades trabaja cada médico.
 *
 * La especialidad es un **atributo del médico** que enciende módulos clínicos: el menú de `/clinica`
 * sale de `especialidad_modulo` para la especialidad del contexto y `ContextoTrabajo` sólo ofrece las
 * que están **activas** (PLAN-WEB.md, R1/R2). Por eso esta pantalla es la que decide qué ve cada médico
 * al entrar al consultorio, y es la única que escribe el pivote `medico_specialty`.
 *
 * Sólo **asigna y quita** especialidades que ya existen: el catálogo no se da de alta ni se edita acá
 * (lo siembran los manifiestos de `config/especialidades/*.php`, ver `EspecialidadesYModulosSeeder`).
 *
 * Como `Cuentas`, `ApiKeys` y `Servicios`, `hydrate()` vuelve a comprobar el permiso en cada acción de
 * Livewire: la ruta sólo protege la carga de la página.
 */
class Especialidades extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** Pastillas del listado: todos, los que no tienen ninguna (no pueden trabajar) y los que sí. */
    public const FILTROS = ['todos', 'sin', 'con'];

    public $search = '';
    public $filtro = 'todos';

    /** Ventana abierta: `asignar` | null */
    public $modal = null;

    /** Médico sobre el que se está trabajando y especialidades tildadas en la ventana abierta. */
    public $medicoId = null;
    public $seleccionadas = [];

    /** Los **postgrados** tildados (2026-10-19): sólo pueden ser de las especialidades tildadas arriba. */
    public $postgrados = [];

    /** Aviso que no es un error (el médico quedó sin especialidad): se muestra como advertencia. */
    public $aviso = null;

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount()
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    /* ------------------------------------------------------------------ */
    /* Listado                                                             */
    /* ------------------------------------------------------------------ */

    public function updatingSearch()
    {
        $this->aviso = null;
        $this->resetPage();
    }

    public function updatingFiltro()
    {
        $this->resetPage();
    }

    public function filtrar(string $filtro)
    {
        $this->filtro = in_array($filtro, self::FILTROS, true) ? $filtro : 'todos';
        $this->aviso = null;
        $this->resetPage();
    }

    public function render()
    {
        $busqueda = trim($this->search);

        $filas = Medico::with('specialties')
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('lastname', 'like', "%{$busqueda}%")
                ->orWhere('reg_medico', 'like', "%{$busqueda}%")
                ->orWhere('email', 'like', "%{$busqueda}%")))
            ->when($this->filtro === 'sin', fn ($q) => $q->whereDoesntHave('specialties'))
            ->when($this->filtro === 'con', fn ($q) => $q->whereHas('specialties'))
            ->orderBy('name')
            ->orderBy('lastname')
            ->paginate(15);

        // El catálogo completo, activas primero: una inactiva se puede ver asignada, pero no da módulos.
        $catalogo = Specialty::with(['subespecialidades' => fn ($q) => $q->where('activo', true)])
            ->orderByDesc('activo')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.especialidades', [
            'filas'       => $filas,
            'conteo'      => $this->conteo(),
            'catalogo'    => $catalogo,
            'sinActivas'  => $catalogo->where('activo', false)->count(),
            'seleccionado' => $this->modal === 'asignar' && $this->medicoId ? Medico::find($this->medicoId) : null,
        ]);
    }

    /** @return array<string,int> cuántos médicos hay en cada pastilla */
    private function conteo(): array
    {
        return [
            'todos' => Medico::count(),
            'sin'   => Medico::whereDoesntHave('specialties')->count(),
            'con'   => Medico::whereHas('specialties')->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Asignación                                                          */
    /* ------------------------------------------------------------------ */

    public function abrirAsignar(int $medicoId)
    {
        $medico = Medico::findOrFail($medicoId);

        $this->medicoId = $medico->id;
        // El formulario manda los ids como texto: se comparan con los del pivote tal cual llegan.
        $this->seleccionadas = $medico->specialties()->pluck('specialties.id')->map(fn ($id) => (string) $id)->all();
        $this->postgrados = $medico->subespecialidades()->pluck('subespecialidades.id')->map(fn ($id) => (string) $id)->all();
        $this->aviso = null;
        $this->resetErrorBag();
        $this->modal = 'asignar';
    }

    public function guardarAsignacion(CuentaService $cuentas)
    {
        try {
            $medico = Medico::findOrFail($this->medicoId);
            $cambios = $cuentas->asignarEspecialidades($medico, (array) $this->seleccionadas);
            // Los postgrados van **después**: asignar especialidades puede haber quitado los que quedaron sin su
            // especialidad, y acá se guarda lo que quedó tildado en el formulario.
            $cuentas->asignarSubespecialidades($medico->fresh('specialties'), (array) $this->postgrados);

            session()->flash('message', $this->textoDeGuardado($medico, $cambios));
            $this->aviso = $this->advertenciaSinEspecialidad($medico->fresh('specialties'));
            $this->cerrarModal();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }
        }
    }

    public function cerrarModal()
    {
        $this->modal = null;
        $this->medicoId = null;
        $this->seleccionadas = [];
        $this->postgrados = [];
        $this->resetErrorBag();
    }

    /** @param  array{asignadas:int, quitadas:int}  $cambios */
    private function textoDeGuardado(Medico $medico, array $cambios): string
    {
        $nombre = trim($medico->name . ' ' . $medico->lastname);

        if ($cambios['asignadas'] === 0 && $cambios['quitadas'] === 0) {
            return "Sin cambios: {$nombre} ya tenía esas especialidades.";
        }

        $partes = [];
        if ($cambios['asignadas'] > 0) {
            $partes[] = $cambios['asignadas'] . ' asignada' . ($cambios['asignadas'] > 1 ? 's' : '');
        }
        if ($cambios['quitadas'] > 0) {
            $partes[] = $cambios['quitadas'] . ' quitada' . ($cambios['quitadas'] > 1 ? 's' : '');
        }

        if (($cambios['postgradosQuitados'] ?? 0) > 0) {
            $partes[] = $cambios['postgradosQuitados'] . ' postgrado' . ($cambios['postgradosQuitados'] > 1 ? 's' : '')
                . ' quitado' . ($cambios['postgradosQuitados'] > 1 ? 's' : '') . ' por quedarse sin su especialidad';
        }

        return "Especialidades de {$nombre} actualizadas: " . implode(' y ', $partes) . '.';
    }

    /**
     * Sin ninguna especialidad **activa** el consultorio no muestra módulos: no es un error (se guardó),
     * pero hay que decirlo, porque el médico entra a `/clinica` y no encuentra nada.
     */
    private function advertenciaSinEspecialidad(Medico $medico): ?string
    {
        $activas = $medico->specialties->where('activo', true)->count();
        if ($activas > 0) {
            return null;
        }

        $nombre = trim($medico->name . ' ' . $medico->lastname);

        return $medico->specialties->isEmpty()
            ? "{$nombre} quedó sin ninguna especialidad: su consultorio en la web no va a mostrar módulos hasta que le asignes una."
            : "Las especialidades de {$nombre} están inactivas, así que su consultorio en la web no va a mostrar módulos.";
    }
}
