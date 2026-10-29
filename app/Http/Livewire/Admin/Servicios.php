<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Pagos\Centavos;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use App\Support\CaracteristicasPlan;
use App\Support\RestriccionesPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "Planes y servicios" del panel (Paso 27): el catálogo de planes y el servicio contratado por cada
 * médico (qué plan tiene, hasta cuándo, renovarlo). La lógica vive en `ServicioService`; acá solo el panel.
 *
 * Dos pestañas: `servicios` (por médico) y `planes` (el catálogo). Los planes tienen **límites de equipo**
 * (`restricciones`) y **características/cobro** (`caracteristicas`) editables; los cuatro planes de ejemplo
 * son filas como cualquier otra.
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

    // Ventana abierta: renovar | reemplazar | quitar | historial | plan | null
    public $modal = null;

    // Renovar / reemplazar / cancelar el servicio de un médico
    public $medicoId = null;
    public $planElegido = null;
    public $meses = 1;
    public $monto = 0;
    public $nota = '';
    public $motivo = ''; // obligatorio al reemplazar o cancelar: queda en el historial

    // Plan (alta o edición)
    public $planId = null;
    public $codigo = '';
    public $nombre = '';
    public $descripcion = '';
    public $frecuencia = Plan::MENSUAL;
    public $precio_usd = '';
    public $precio_anual_usd = '';
    public $precio_tachado_usd = '';
    public $orden = 0;
    public $visible = true;
    public $activo = true;
    public $es_default = false;

    // Límites de equipo y características del plan
    public $max_medicos = '';
    public $max_secretarias = '';
    public $impresion_reportes = true;
    public $precio_asiento_usd = '';
    public $secretarias_incluidas = 0;
    public $minimo_asientos = 0;
    public $meses_cobrados_anual = 10;

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount()
    {
        $this->autorizar();
        // Cada pestaña es un submenú con su URL: `admin.servicios.planes` abre los planes.
        $this->pestana = request()->route()?->getName() === 'admin.servicios.planes' ? 'planes' : $this->pestana;
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
            $datos['inicioRenovacion'] = $inicio ? $inicio->format(\App\Support\Regionalizacion::formatoActual()) . ' (cuando termina el actual)' : 'hoy';
        }
        if ($seleccionado && in_array($this->modal, ['reemplazar', 'quitar'], true)) {
            $datos['actual'] = $servicios->estado((string) $seleccionado->reg_medico);
        }
        $datos['planesActivos'] = in_array($this->modal, ['renovar', 'reemplazar'], true) ? Plan::where('activo', true)->orderBy('orden')->orderBy('id')->get() : collect();

        // Los planes que no cubren al equipo actual se marcan en el desplegable (salvo el que ya tiene).
        $datos['planesNoCubren'] = [];
        if ($seleccionado && in_array($this->modal, ['renovar', 'reemplazar'], true)) {
            $cupos = app(CupoDelPlan::class);
            $actual = $servicios->actual((string) $seleccionado->reg_medico);

            foreach ($datos['planesActivos'] as $plan) {
                if ($actual && (int) $actual->plan_id === (int) $plan->id) {
                    continue;
                }
                $motivo = $cupos->motivoNoCabe($plan, (string) $seleccionado->reg_medico);
                if ($motivo !== null) {
                    $datos['planesNoCubren'][$plan->id] = $motivo;
                }
            }
        }

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
        $this->aplicarPlan($plan, $medico);
    }

    /** Al cambiar de plan en la ventana, meses y monto se llenan con los del plan (y se pueden retocar). */
    public function updatedPlanElegido($valor)
    {
        $this->aplicarPlan(Plan::where('activo', true)->find($valor), $this->medicoId ? Medico::find($this->medicoId) : null);
    }

    private function aplicarPlan(?Plan $plan, ?Medico $medico = null): void
    {
        $this->planElegido = $plan?->id;

        if ($plan === null) {
            $this->meses = 1;
            $this->monto = 0;

            return;
        }

        $frecuencia = $plan->frecuenciaPorDefecto();
        $this->meses = $plan->meses($frecuencia);

        if ($plan->cobraPorAsiento() && $medico !== null) {
            $cupos = app(CupoDelPlan::class);
            $this->monto = Centavos::aDecimal($cupos->precioUsdE2(
                $plan,
                $cupos->conteo((string) $medico->reg_medico),
                $frecuencia,
            ));

            return;
        }

        $this->monto = $frecuencia === Plan::ANUAL
            ? (float) ($plan->precio_anual_usd ?? ($plan->frecuencia === Plan::ANUAL ? $plan->precio_usd : 0))
            : (float) $plan->precio_usd;
    }

    /** El plan elegido tiene que cubrir al equipo (salvo que sea la renovación del mismo plan). */
    private function exigirQueCubra(Plan $plan, Medico $medico): bool
    {
        try {
            app(CupoDelPlan::class)->exigirQueCubra($plan, (string) $medico->reg_medico);

            return true;
        } catch (ValidationException $e) {
            $this->addError('planElegido', $e->errors()['plan'][0] ?? $e->getMessage());

            return false;
        }
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
        $plan = Plan::findOrFail($this->planElegido);
        if (! $this->exigirQueCubra($plan, $medico)) {
            return;
        }

        $monto = (float) $this->monto;
        $nuevo = $servicios->renovar(
            (string) $medico->reg_medico,
            $plan,
            $monto,
            trim((string) $this->nota) ?: null,
            $monto > 0 ? RegMedicoServicio::ORIGEN_COMPRA : RegMedicoServicio::ORIGEN_MANUAL,
            (int) $this->meses,
        );

        session()->flash('message', trim($medico->name . ' ' . $medico->lastname) . ': servicio hasta el ' . $nuevo->vence_el->format(\App\Support\Regionalizacion::formatoActual()) . '.');
        $this->cerrarModal();
    }

    /**
     * "Modificar → Reemplazar": otro plan **desde hoy**, sin conservar el tiempo que quedaba. Para corregir una
     * incidencia o un fraude sin esperar a que termine el período actual.
     */
    public function abrirReemplazar(int $medicoId, ServicioService $servicios)
    {
        $medico = Medico::findOrFail($medicoId);
        $actual = $servicios->actual((string) $medico->reg_medico);
        if (! $actual) {
            session()->flash('error', 'Este médico no tiene servicio que reemplazar: usa Renovar para asignarle un plan.');

            return;
        }
        $plan = ($actual->plan_id ? Plan::where('activo', true)->find($actual->plan_id) : null)
            ?? Plan::where('activo', true)->where('visible', true)->orderBy('orden')->first()
            ?? Plan::porDefecto();

        $this->resetErrorBag();
        $this->medicoId = $medico->id;
        $this->motivo = '';
        $this->modal = 'reemplazar';
        $this->aplicarPlan($plan, $medico);
    }

    public function reemplazar(ServicioService $servicios)
    {
        $this->validate([
            'planElegido' => ['required', Rule::exists('planes', 'id')->where('activo', true)],
            'meses' => 'required|integer|min:1|max:60',
            'monto' => 'required|numeric|min:0|max:99999',
            'motivo' => 'required|string|min:3|max:150',
        ], [], ['planElegido' => 'plan', 'meses' => 'meses', 'monto' => 'monto', 'motivo' => 'motivo']);

        $medico = Medico::findOrFail($this->medicoId);
        if (! $servicios->actual((string) $medico->reg_medico)) {
            session()->flash('error', 'Este médico no tiene servicio que reemplazar: usa Renovar para asignarle un plan.');
            $this->cerrarModal();

            return;
        }

        $plan = Plan::findOrFail($this->planElegido);
        if (! $this->exigirQueCubra($plan, $medico)) {
            return;
        }

        $nuevo = $servicios->reemplazar(
            (string) $medico->reg_medico,
            $plan,
            (float) $this->monto,
            $this->motivo,
            (int) $this->meses,
            auth()->user()->name,
        );

        session()->flash('message', trim($medico->name . ' ' . $medico->lastname) . ': plan reemplazado, servicio hasta el ' . $nuevo->vence_el->format(\App\Support\Regionalizacion::formatoActual()) . '.');
        $this->cerrarModal();
    }

    /** "Modificar → Quitar plan": le quita el servicio a ese médico (una sola persona; no hay cancelación en lote). */
    public function abrirQuitar(int $medicoId, ServicioService $servicios)
    {
        $medico = Medico::findOrFail($medicoId);
        if (! $servicios->actual((string) $medico->reg_medico)) {
            session()->flash('error', 'Este médico ya no tiene servicio.');

            return;
        }

        $this->resetErrorBag();
        $this->medicoId = $medico->id;
        $this->motivo = '';
        $this->modal = 'quitar';
    }

    public function quitarPlan(ServicioService $servicios)
    {
        $this->validate(['motivo' => 'required|string|min:3|max:150'], [], ['motivo' => 'motivo']);

        $medico = Medico::findOrFail($this->medicoId);
        $cancelados = $servicios->cancelarActivos((string) $medico->reg_medico, $this->motivo, auth()->user()->name);

        session()->flash('message', $cancelados === 0
            ? trim($medico->name . ' ' . $medico->lastname) . ' ya no tenía servicio.'
            : trim($medico->name . ' ' . $medico->lastname) . ': plan quitado, ya no sincroniza. Para devolvérselo, asígnale un plan con Renovar.');
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
        $this->reset([
            'planId', 'codigo', 'nombre', 'descripcion', 'precio_usd', 'precio_anual_usd', 'precio_tachado_usd', 'orden', 'es_default',
            'max_medicos', 'max_secretarias', 'precio_asiento_usd',
        ]);
        $this->frecuencia = Plan::MENSUAL;
        $this->visible = true;
        $this->activo = true;
        $this->impresion_reportes = true;
        $this->secretarias_incluidas = 0;
        $this->minimo_asientos = 0;
        $this->meses_cobrados_anual = 10;
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
        $this->precio_anual_usd = $plan->precio_anual_usd === null ? '' : (string) $plan->precio_anual_usd;
        $this->precio_tachado_usd = $plan->precio_tachado_usd === null ? '' : (string) $plan->precio_tachado_usd;
        $this->orden = (int) $plan->orden;
        $this->visible = (bool) $plan->visible;
        $this->activo = (bool) $plan->activo;
        $this->es_default = (bool) $plan->es_default;
        $this->max_medicos = $plan->restricciones->maxMedicos();
        $this->max_secretarias = $plan->restricciones->maxSecretarias();
        $this->impresion_reportes = $plan->caracteristicas->impresionReportes();
        $this->precio_asiento_usd = $plan->caracteristicas->precioAsientoUsd();
        $this->secretarias_incluidas = $plan->caracteristicas->secretariasIncluidas();
        $this->minimo_asientos = $plan->caracteristicas->minimoAsientos();
        $this->meses_cobrados_anual = $plan->caracteristicas->mesesCobradosAnual();
        $this->modal = 'plan';
    }

    public function guardarPlan()
    {
        // Los campos vacíos significan “sin límite” / “precio fijo” / “sin precio anual”.
        $this->max_medicos = $this->max_medicos === '' ? null : $this->max_medicos;
        $this->max_secretarias = $this->max_secretarias === '' ? null : $this->max_secretarias;
        $this->precio_asiento_usd = $this->precio_asiento_usd === '' ? null : $this->precio_asiento_usd;
        $this->precio_anual_usd = $this->precio_anual_usd === '' ? null : $this->precio_anual_usd;

        $this->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/', Rule::unique('planes', 'codigo')->ignore($this->planId)],
            'nombre' => 'required|string|max:100',
            'descripcion' => 'nullable|string|max:255',
            'frecuencia' => ['required', Rule::in([Plan::MENSUAL, Plan::ANUAL])],
            'precio_usd' => 'required|numeric|min:0|max:99999',
            'precio_anual_usd' => 'nullable|numeric|min:0.01|max:99999',
            'precio_tachado_usd' => 'nullable|numeric|min:0|max:99999',
            'orden' => 'required|integer|min:0|max:9999',
            'max_medicos' => 'nullable|integer|min:1|max:999',
            'max_secretarias' => 'nullable|integer|min:0|max:999',
            'impresion_reportes' => 'boolean',
            'precio_asiento_usd' => 'nullable|numeric|min:0.01|max:9999',
            'secretarias_incluidas' => 'required|integer|min:0|max:999',
            'minimo_asientos' => 'required|integer|min:0|max:999',
            'meses_cobrados_anual' => 'required|integer|min:0|max:12',
        ], [
            'codigo.regex' => 'Solo minúsculas, números, guion y guion bajo (sin espacios).',
            'codigo.unique' => 'Ya hay un plan con ese código.',
            'max_medicos.min' => 'El plan tiene que permitir al menos 1 médico (vacío = sin límite).',
            'precio_asiento_usd.min' => 'El precio por asiento tiene que ser mayor que cero (vacío = precio fijo).',
            'precio_anual_usd.min' => 'El precio anual tiene que ser mayor que cero (vacío = sin anual).',
        ], [
            'codigo' => 'código', 'nombre' => 'nombre', 'precio_usd' => 'monto mensual', 'precio_anual_usd' => 'monto anual',
            'precio_tachado_usd' => 'monto tachado',
            'max_medicos' => 'máximo de médicos', 'max_secretarias' => 'máximo de secretarías',
            'precio_asiento_usd' => 'precio por asiento', 'secretarias_incluidas' => 'secretarías incluidas',
            'minimo_asientos' => 'mínimo de asientos', 'meses_cobrados_anual' => 'meses que se cobran al año',
        ]);

        $esPorAsiento = $this->precio_asiento_usd !== null;
        $tieneMensual = $esPorAsiento || (
            ! ($this->frecuencia === Plan::ANUAL && $this->precio_anual_usd === null)
            && (float) $this->precio_usd > 0
        );
        $tieneAnual = $esPorAsiento
            ? (int) $this->meses_cobrados_anual > 0
            : (
                ($this->precio_anual_usd !== null && (float) $this->precio_anual_usd > 0)
                || ($this->frecuencia === Plan::ANUAL && (float) $this->precio_usd > 0)
            );

        // La frecuencia principal se ajusta a lo que el plan realmente ofrece (un plan gratuito no se toca).
        if ($tieneMensual || $tieneAnual) {
            if ($this->frecuencia === Plan::ANUAL && ! $tieneAnual) {
                $this->frecuencia = Plan::MENSUAL;
            } elseif ($this->frecuencia === Plan::MENSUAL && ! $tieneMensual) {
                $this->frecuencia = Plan::ANUAL;
            }
        }

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
            'precio_anual_usd' => $this->precio_anual_usd === null ? null : (float) $this->precio_anual_usd,
            'precio_tachado_usd' => $this->precio_tachado_usd === '' || $this->precio_tachado_usd === null ? null : (float) $this->precio_tachado_usd,
            'orden' => (int) $this->orden,
            'visible' => (bool) $this->visible,
            'activo' => (bool) $this->activo,
            'es_default' => (bool) $this->es_default,
            'restricciones' => RestriccionesPlan::desde($actual?->restricciones)->con([
                'max_medicos' => $this->max_medicos === null ? null : (int) $this->max_medicos,
                'max_secretarias' => $this->max_secretarias === null ? null : (int) $this->max_secretarias,
            ])->toArray(),
            'caracteristicas' => CaracteristicasPlan::desde($actual?->caracteristicas)->con([
                'impresion_reportes' => (bool) $this->impresion_reportes,
                'precio_asiento_usd' => $this->precio_asiento_usd === null ? null : (float) $this->precio_asiento_usd,
                'secretarias_incluidas' => (int) $this->secretarias_incluidas,
                'minimo_asientos' => (int) $this->minimo_asientos,
                'meses_cobrados_anual' => (int) $this->meses_cobrados_anual,
            ])->toArray(),
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
