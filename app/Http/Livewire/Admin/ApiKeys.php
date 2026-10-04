<?php

namespace App\Http\Livewire\Admin;

use App\Http\Livewire\Admin\Concerns\EmiteApiKeys;
use App\Models\Medico;
use App\Models\SyncCredential;
use App\Services\SyncCredencialService;
use App\Services\SyncResumenService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "API Keys" del panel (Paso 26.C): la credencial de sincronización que usa el escritorio del
 * consultorio (GinecoReport) y cuándo sincronizó cada médico.
 *
 * Es lo que antes se hacía con `php artisan sync:credencial emitir`: la lógica vive en `SyncCredencialService`
 * y los datos de "última sincronización" y conteos en `SyncResumenService`.
 *
 * El token en claro se muestra UNA sola vez (`$tokenGenerado`); en la base solo queda su hash. Igual que en
 * `Cuentas`, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire.
 */
class ApiKeys extends Component
{
    use WithPagination;
    use EmiteApiKeys;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    // Ventana abierta: emitir | credenciales | null
    public $modal = null;
    public $medicoId = null;
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

    public function render(SyncResumenService $resumenes)
    {
        $busqueda = trim($this->search);

        $medicos = Medico::query()
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('lastname', 'like', "%{$busqueda}%")
                ->orWhere('reg_medico', 'like', "%{$busqueda}%")))
            ->orderBy('name')->orderBy('lastname')
            ->paginate(10);

        $resumen = $resumenes->resumir($medicos->getCollection());

        $seleccionado = $this->medicoId ? Medico::find($this->medicoId) : null;

        return view('livewire.admin.api-keys', [
            'medicos'      => $medicos,
            'resumen'      => $resumen,
            'seleccionado' => $seleccionado,
            // Las credenciales de la ventana "Credenciales" se leen frescas para que revocar se vea al instante.
            'credenciales' => $this->modal === 'credenciales' && $seleccionado
                ? ($resumenes->resumir(collect([$seleccionado]))[$seleccionado->id]['credenciales'] ?? collect())
                : collect(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Credenciales de un médico                                           */
    /* ------------------------------------------------------------------ */

    public function verCredenciales(int $medicoId)
    {
        $this->medicoId = Medico::findOrFail($medicoId)->id;
        $this->modal = 'credenciales';
    }

    public function revocar(int $credencialId, SyncCredencialService $credenciales)
    {
        $credenciales->revocar(SyncCredential::findOrFail($credencialId));
        session()->flash('message', 'Credencial revocada: ese equipo ya no puede sincronizar.');
    }

    /* ------------------------------------------------------------------ */
    /* Ventanas                                                            */
    /* ------------------------------------------------------------------ */

    public function cerrarModal()
    {
        $this->modal = null;
        $this->medicoId = null;
        $this->reemplazar = false;
        $this->mostrarReemplazo = false;
        $this->resetErrorBag();
    }

}
