<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Services\ServicioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "Planes y servicios" del panel (Paso 27): el catálogo de planes y el servicio contratado por cada
 * médico (qué plan tiene, hasta cuándo, renovarlo). La lógica vive en `ServicioService`; acá solo el panel.
 *
 * Dos pestañas: `servicios` (por médico) y `planes` (el catálogo). Por ahora los planes no tienen restricciones
 * editables (máx. de médicos, histórico…): se agregarán cuando se definan.
 *
 * Igual que `Cuentas` y `ApiKeys`, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire.
 */
class Servicios extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public const FILTROS = ['todos', 'vigente', 'por_vencer', 'gracia', 'vencido', 'sin_servicio'];

    /** Días para considerar que un servicio vigente está "por vencer". */
    public const POR_VENCER_DIAS = 30;

    public $pestana = 'servicios'; // servicios | planes
    public $search = '';
    public $filtro = 'todos';

    // Ventana abierta: renovar | historial | plan | null
    public $modal = null;

    // Renovar
    public $medicoId = null;
    public $planElegido = null;
    public $meses = 1;
    public $monto = 0;
    public $nota = '';

    // Plan (alta o edición)
    public $planId = null;
    public $codigo = '';
    public $nombre = '';
    public $descripcion = '';
    public $frecuencia = Plan::MENSUAL;
    public $precio_usd = '';
    public $precio_tachado_usd = '';
    public $orden = 0;
    public $visible = true;
    public $activo = true;
    public $es_default = false;

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

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFiltro()
    {
        $this->resetPage();
    }

    public function cambiarPestana(string $pestana)
    {
        $this->pestana = $pestana === 'planes' ? 'planes' : 'servicios';
        $this->cerrarModal();
    }

    public function filtrar(string $filtro)
    {
        $this->filtro = in_array($filtro, self::FILTROS, true) ? $filtro : 'todos';
        $this->resetPage();
    }

    /* ------------------------------------------------------------------ */
    /* Render                                                              */
    /* ------------------------------------------------------------------ */

    public function render(ServicioService $servicios)
    {
        $datos = ['planes' => collect(), 'medicos' => null, 'conteo' => [], 'filas' => [], 'seleccionado' => null, 'historial' => collect()];

        if ($this->pestana === 'planes') {
            $datos['planes'] = Plan::orderBy('orden')->orderBy('id')->get();
            $datos['contratos'] = RegMedicoServicio::selectRaw('plan_id, count(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');
        } else {
            [$conteo, $regsPorEstado] = $this->clasificarMedicos($servicios);
            $busqueda = trim($this->search);

            $medicos = Medico::query()
                ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('lastname', 'like', "%{$busqueda}%")
                    ->orWhere('reg_medico', 'like', "%{$busqueda}%")))
                ->when($this->filtro !== 'todos', fn ($q) => $q->whereIn('reg_medico', $regsPorEstado[$this->filtro] ?? []))
                ->orderBy('name')->orderBy('lastname')
                ->paginate(10);

            $filas = [];
            foreach ($medicos as $medico) {
                $filas[$medico->id] = $servicios->estado((string) $medico->reg_medico);
            }
            $datos['medicos'] = $medicos;
            $datos['conteo'] = $conteo;
            $datos['filas'] = $filas;
        }

        $seleccionado = $this->medicoId ? Medico::find($this->medicoId) : null;
        $datos['seleccionado'] = $seleccionado;
        if ($seleccionado && $this->modal === 'historial') {
            $datos['historial'] = RegMedicoServicio::where('reg_medico', $seleccionado->reg_medico)->orderByDesc('vence_el')->orderByDesc('id')->get();
        }
        if ($seleccionado && $this->modal === 'renovar') {
            $inicio = $servicios->inicioDeRenovacion((string) $seleccionado->reg_medico);
            $datos['inicioRenovacion'] = $inicio ? $inicio->format('d/m/Y') . ' (cuando termina el actual)' : 'hoy';
        }
        $datos['planesActivos'] = $this->modal === 'renovar' ? Plan::where('activo', true)->orderBy('orden')->orderBy('id')->get() : collect();

        return view('livewire.admin.servicios', $datos);
    }

    /**
     * Cuántos médicos hay en cada estado (los contadores que también filtran) y los `reg_medico` de cada uno.
     *
     * @return array{0: array<string,int>, 1: array<string, list<string>>}
     */
    private function clasificarMedicos(ServicioService $servicios): array
    {
        $vencimientos = $servicios->vencimientos();
        $porEstado = ['vigente' => [], 'por_vencer' => [], 'gracia' => [], 'vencido' => [], 'sin_servicio' => []];

        foreach (Medico::pluck('reg_medico') as $reg) {
            $reg = (string) $reg;
            if (! isset($vencimientos[$reg])) {
                $porEstado['sin_servicio'][] = $reg;
                continue;
            }
            $c = $servicios->clasificar($vencimientos[$reg]);
            $clave = $c['estado'] === ServicioService::VIGENTE && $c['dias'] <= self::POR_VENCER_DIAS ? 'por_vencer' : $c['estado'];
            $porEstado[$clave][] = $reg;
        }
        $conteo = array_map('count', $porEstado);
        $conteo['todos'] = Medico::count();

        return [$conteo, $porEstado];
    }

    /* ------------------------------------------------------------------ */
    /* Servicio de un médico                                               */
    /* ------------------------------------------------------------------ */

    public function abrirRenovar(int $medicoId, ServicioService $servicios)
    {
        $medico = Medico::findOrFail($medicoId);
        $actual = $servicios->actual((string) $medico->reg_medico);
        $plan = ($actual?->plan_id ? Plan::where('activo', true)->find($actual->plan_id) : null)
            ?? Plan::where('activo', true)->where('visible', true)->orderBy('orden')->first()
            ?? Plan::porDefecto();

        $this->resetErrorBag();
        $this->medicoId = $medico->id;
        $this->nota = '';
        $this->modal = 'renovar';
        $this->aplicarPlan($plan);
    }

    /** Al cambiar de plan en la ventana, meses y monto se llenan con los del plan (y se pueden retocar). */
    public function updatedPlanElegido($valor)
    {
        $this->aplicarPlan(Plan::where('activo', true)->find($valor));
    }

    private function aplicarPlan(?Plan $plan): void
    {
        $this->planElegido = $plan?->id;
        $this->meses = $plan?->meses() ?? 1;
        $this->monto = $plan ? (float) $plan->precio_usd : 0;
    }

    public function renovar(ServicioService $servicios)
    {
        $this->validate([
            'planElegido' => ['required', Rule::exists('planes', 'id')->where('activo', true)],
            'meses' => 'required|integer|min:1|max:60',
            'monto' => 'required|numeric|min:0|max:99999',
            'nota' => 'nullable|string|max:255',
        ], [], ['planElegido' => 'plan', 'meses' => 'meses', 'monto' => 'monto']);

        $medico = Medico::findOrFail($this->medicoId);
        $monto = (float) $this->monto;
        $nuevo = $servicios->renovar(
            (string) $medico->reg_medico,
            Plan::findOrFail($this->planElegido),
            $monto,
            trim((string) $this->nota) ?: null,
            $monto > 0 ? RegMedicoServicio::ORIGEN_COMPRA : RegMedicoServicio::ORIGEN_MANUAL,
            (int) $this->meses,
        );

        session()->flash('message', trim($medico->name . ' ' . $medico->lastname) . ': servicio hasta el ' . $nuevo->vence_el->format('d/m/Y') . '.');
        $this->cerrarModal();
    }

    public function verHistorial(int $medicoId)
    {
        $this->medicoId = Medico::findOrFail($medicoId)->id;
        $this->modal = 'historial';
    }

    public function cancelarServicio(int $servicioId, ServicioService $servicios)
    {
        $servicio = RegMedicoServicio::findOrFail($servicioId);
        // Solo desde la ventana del historial de ese médico: que no se pueda cancelar el de otro por error.
        $medico = $this->medicoId ? Medico::find($this->medicoId) : null;
        abort_unless($medico && $medico->reg_medico === $servicio->reg_medico, 403);

        $servicios->cancelar($servicio);
        session()->flash('message', 'Servicio cancelado: ya no cuenta para el vencimiento.');
    }

    /* ------------------------------------------------------------------ */
    /* Planes                                                              */
    /* ------------------------------------------------------------------ */

    public function nuevoPlan()
    {
        $this->resetErrorBag();
        $this->reset(['planId', 'codigo', 'nombre', 'descripcion', 'precio_usd', 'precio_tachado_usd', 'orden', 'es_default']);
        $this->frecuencia = Plan::MENSUAL;
        $this->visible = true;
        $this->activo = true;
        $this->orden = (int) Plan::max('orden') + 1;
        $this->modal = 'plan';
    }

    public function editarPlan(int $planId)
    {
        $plan = Plan::findOrFail($planId);
        $this->resetErrorBag();
        $this->planId = $plan->id;
        $this->codigo = $plan->codigo;
        $this->nombre = $plan->nombre;
        $this->descripcion = (string) $plan->descripcion;
        $this->frecuencia = $plan->frecuencia;
        $this->precio_usd = (string) $plan->precio_usd;
        $this->precio_tachado_usd = $plan->precio_tachado_usd === null ? '' : (string) $plan->precio_tachado_usd;
        $this->orden = (int) $plan->orden;
        $this->visible = (bool) $plan->visible;
        $this->activo = (bool) $plan->activo;
        $this->es_default = (bool) $plan->es_default;
        $this->modal = 'plan';
    }

    public function guardarPlan()
    {
        $this->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('planes', 'codigo')->ignore($this->planId)],
            'nombre' => 'required|string|max:100',
            'descripcion' => 'nullable|string|max:255',
            'frecuencia' => ['required', Rule::in([Plan::MENSUAL, Plan::ANUAL])],
            'precio_usd' => 'required|numeric|min:0|max:99999',
            'precio_tachado_usd' => 'nullable|numeric|min:0|max:99999',
            'orden' => 'required|integer|min:0|max:9999',
        ], [
            'codigo.regex' => 'Solo minúsculas, números, guion y guion bajo (sin espacios).',
            'codigo.unique' => 'Ya hay un plan con ese código.',
        ], [
            'codigo' => 'código', 'nombre' => 'nombre', 'precio_usd' => 'monto', 'precio_tachado_usd' => 'monto tachado',
        ]);

        $actual = $this->planId ? Plan::findOrFail($this->planId) : null;

        // Siempre tiene que haber un plan por defecto (el que recibe un médico recién registrado): no se
        // desmarca ni se desactiva el actual, se marca otro y el anterior se desmarca solo.
        if ($actual && $actual->es_default && (! $this->es_default || ! $this->activo)) {
            $this->addError('es_default', 'Este es el plan por defecto: para cambiarlo, marca otro plan como predeterminado.');

            return;
        }
        if ($this->es_default && ! $this->activo) {
            $this->addError('activo', 'El plan por defecto tiene que estar activo.');

            return;
        }

        $datos = [
            'codigo' => trim($this->codigo),
            'nombre' => trim($this->nombre),
            'descripcion' => trim((string) $this->descripcion) ?: null,
            'frecuencia' => $this->frecuencia,
            'precio_usd' => (float) $this->precio_usd,
            'precio_tachado_usd' => $this->precio_tachado_usd === '' || $this->precio_tachado_usd === null ? null : (float) $this->precio_tachado_usd,
            'orden' => (int) $this->orden,
            'visible' => (bool) $this->visible,
            'activo' => (bool) $this->activo,
            'es_default' => (bool) $this->es_default,
        ];

        DB::transaction(function () use ($actual, $datos) {
            $actual ? $actual->update($datos) : Plan::create($datos);
        });

        session()->flash('message', 'Plan "' . $datos['nombre'] . '" guardado.');
        $this->cerrarModal();
    }

    /** Activa o desactiva un plan sin abrir la ventana. El plan por defecto no se desactiva. */
    public function alternarActivo(int $planId)
    {
        $plan = Plan::findOrFail($planId);
        if ($plan->activo && $plan->es_default) {
            session()->flash('error', 'El plan por defecto no se puede desactivar: marca otro como predeterminado primero.');

            return;
        }
        $plan->update(['activo' => ! $plan->activo]);
        session()->flash('message', 'Plan "' . $plan->nombre . '" ' . ($plan->activo ? 'activado' : 'desactivado') . '.');
    }

    /* ------------------------------------------------------------------ */
    /* Ventanas                                                            */
    /* ------------------------------------------------------------------ */

    public function cerrarModal()
    {
        $this->modal = null;
        $this->medicoId = null;
        $this->planId = null;
        $this->resetErrorBag();
    }
}
