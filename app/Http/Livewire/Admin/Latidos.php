<?php

namespace App\Http\Livewire\Admin;

use App\Latidos\Conversion;
use App\Latidos\Latidos as ServicioDeLatidos;
use App\Models\Latido;
use App\Models\Medico;
use App\Models\RetencionDeLatidos;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * **Latidos** (`/admin/latidos`): el panel de soporte del programa de descuentos.
 *
 * Muestra los Latidos de cada consultorio, lo retenido por pagos pendientes y el libro de movimientos. El
 * **ajuste manual** es sólo de Root y siempre pide un motivo: es dinero simbólico, pero es un descuento que
 * alguien va a usar y tiene que quedar auditado.
 *
 * Como `Servicios` y `Pagos`, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire.
 */
class Latidos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    /** La fila abierta en el detalle, si hay una. */
    public $latidoId = null;

    // Ajuste manual (sólo Root)
    public $ajusteLatidos = '';
    public $ajusteMotivo = '';
    public $permitirNegativo = false;

    public function mount(): void
    {
        $this->autorizar();
    }

    public function hydrate(): void
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function abrir(int $latidoId): void
    {
        $this->latidoId = Latido::findOrFail($latidoId)->id;
        $this->resetErrorBag();
        $this->ajusteLatidos = '';
        $this->ajusteMotivo = '';
        $this->permitirNegativo = false;
    }

    public function cerrar(): void
    {
        $this->latidoId = null;
        $this->resetErrorBag();
    }

    /**
     * Ajuste manual de Latidos. Positivo acredita, negativo descuenta. Sólo Root; el motivo es obligatorio y
     * queda en `movimientos_de_latidos` con el autor.
     */
    public function ajustar(ServicioDeLatidos $servicio): void
    {
        $this->autorizar();
        abort_unless(auth()->user()?->hasRole('Root'), 403);

        $datos = $this->validate([
            'ajusteLatidos' => ['required', 'integer', 'not_in:0'],
            'ajusteMotivo' => ['required', 'string', 'min:5', 'max:200'],
        ], [
            'ajusteLatidos.required' => 'Escribe cuántos Latidos ajustar.',
            'ajusteLatidos.integer' => 'Los Latidos van en números enteros.',
            'ajusteLatidos.not_in' => 'El ajuste no puede ser cero.',
            'ajusteMotivo.required' => 'Escribe el motivo del ajuste.',
            'ajusteMotivo.min' => 'El motivo tiene que explicar el ajuste (mínimo 5 caracteres).',
        ]);

        $latido = Latido::findOrFail($this->latidoId);

        try {
            $movimiento = $servicio->ajustar(
                $latido->reg_medico,
                (int) $datos['ajusteLatidos'],
                $datos['ajusteMotivo'],
                auth()->user(),
                (bool) $this->permitirNegativo,
            );
        } catch (RuntimeException $e) {
            $this->addError('ajuste', $e->getMessage());

            return;
        }

        session()->flash(
            'message',
            'Ajuste registrado en ' . $latido->reg_medico . ': ' . Conversion::formatear((int) $movimiento->latidos) . '.',
        );
        $this->cerrar();
    }

    public function esRoot(): bool
    {
        return (bool) auth()->user()?->hasRole('Root');
    }

    public function render()
    {
        $busqueda = trim((string) $this->search);

        $latidos = Latido::query()
            ->when($busqueda !== '', fn ($query) => $query->where('reg_medico', 'like', "%{$busqueda}%"))
            ->orderByDesc('id')
            ->paginate(20);

        $nombres = Medico::query()
            ->whereIn('reg_medico', $latidos->pluck('reg_medico')->filter()->unique()->all())
            ->get()
            ->groupBy('reg_medico')
            ->map(fn ($medicos) => $medicos->first());

        $retenidoPorLatido = RetencionDeLatidos::query()
            ->whereIn('latido_id', $latidos->pluck('id'))
            ->where('estado', RetencionDeLatidos::ACTIVA)
            ->selectRaw('latido_id, sum(latidos) as total')
            ->groupBy('latido_id')
            ->pluck('total', 'latido_id');

        $seleccionado = $this->latidoId
            ? Latido::with([
                'plan',
                'movimientos' => fn ($query) => $query->latest('id')->limit(50),
                'retenciones' => fn ($query) => $query->latest('id')->limit(50),
            ])->find($this->latidoId)
            : null;

        return view('livewire.admin.latidos', [
            'latidos' => $latidos,
            'nombres' => $nombres,
            'retenidoPorLatido' => $retenidoPorLatido,
            'seleccionado' => $seleccionado,
            'totalDisponibles' => (int) Latido::sum('disponibles'),
            'totalRetenido' => (int) RetencionDeLatidos::where('estado', RetencionDeLatidos::ACTIVA)->sum('latidos'),
            'consultorios' => Latido::count(),
            'esRoot' => $this->esRoot(),
        ]);
    }
}
