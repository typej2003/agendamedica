<?php

namespace App\Http\Livewire\Admin;

use App\Models\ReporteEscritorio;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Bandeja de reportes de fallo del escritorio**: lo que mandan el launcher, el instalador y el puente
 * cuando algo se rompe en un consultorio, para no depender de que el médico copie el error.
 *
 * Es de sólo lectura salvo el borrado: los reportes se atienden arreglando el instalador, y se borran
 * cuando el problema ya está resuelto (así la bandeja sirve de lista de pendientes).
 *
 * Igual que `Comentarios` y `Cuentas`, el permiso se vuelve a comprobar en cada acción de Livewire
 * (`hydrate`), no sólo al entrar.
 */
class ReportesEscritorio extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** Texto libre: reg_medico, equipo, mensaje. */
    public $buscar = '';

    /** `''` = todos, o uno de `ReporteEscritorio::TIPOS`. */
    public $tipo = '';

    /** El reporte abierto. */
    public $seleccionado = null;

    /** El reporte que se está por borrar (para el aviso de confirmación). */
    public $porBorrar = null;

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

    public function updatingBuscar(): void
    {
        $this->resetPage();
    }

    public function updatingTipo(): void
    {
        $this->resetPage();
        $this->seleccionado = null;
    }

    public function ver(int $id): void
    {
        $this->seleccionado = ReporteEscritorio::findOrFail($id)->id;
        $this->porBorrar = null;
    }

    public function cerrar(): void
    {
        $this->seleccionado = null;
    }

    public function confirmarBorrado(int $id): void
    {
        $this->porBorrar = ReporteEscritorio::findOrFail($id)->id;
    }

    public function cancelarBorrado(): void
    {
        $this->porBorrar = null;
    }

    public function eliminar(int $id): void
    {
        $reporte = ReporteEscritorio::findOrFail($id);
        $reporte->delete();

        if ((int) $this->seleccionado === $id) {
            $this->seleccionado = null;
        }

        $this->porBorrar = null;

        session()->flash('ok', 'Reporte borrado.');
    }

    public function render()
    {
        $buscar = trim($this->buscar);

        $reportes = ReporteEscritorio::query()
            ->when($this->tipo !== '', fn ($q) => $q->where('tipo', $this->tipo))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $q->where(function ($sub) use ($buscar) {
                    $sub->where('reg_medico', 'like', "%{$buscar}%")
                        ->orWhere('equipo', 'like', "%{$buscar}%")
                        ->orWhere('mensaje', 'like', "%{$buscar}%")
                        ->orWhere('aplicacion', 'like', "%{$buscar}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.reportes-escritorio', [
            'reportes'     => $reportes,
            'abierto'      => $this->seleccionado ? ReporteEscritorio::find($this->seleccionado) : null,
            'tipos'        => ReporteEscritorio::ETIQUETAS,
            'total'        => ReporteEscritorio::count(),
        ]);
    }
}
