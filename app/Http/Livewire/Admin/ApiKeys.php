<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\SyncCredential;
use App\Services\SyncCredencialService;
use App\Services\SyncResumenService;
use DomainException;
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

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    // Ventana abierta: emitir | credenciales | null
    public $modal = null;
    public $medicoId = null;

    // Formulario de emisión
    public $equipo = 'CONSULTORIO-1';
    public $anios = 1;
    public $reemplazar = false;
    public $mostrarReemplazo = false;

    /** Se muestra una sola vez: ['medico','reg_medico','equipo','vence','token']. */
    public $tokenGenerado = null;

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
    /* Emitir                                                              */
    /* ------------------------------------------------------------------ */

    public function abrirEmitir(int $medicoId)
    {
        $this->resetErrorBag();
        $this->medicoId = Medico::findOrFail($medicoId)->id;
        $this->equipo = 'CONSULTORIO-1';
        $this->anios = 1;
        $this->reemplazar = false;
        $this->mostrarReemplazo = false;
        $this->modal = 'emitir';
    }

    public function emitir(SyncCredencialService $credenciales)
    {
        $this->validate([
            'equipo' => 'required|string|max:60',
            'anios'  => 'required|integer|min:1|max:3',
        ], [
            'equipo.required' => 'Escribe el nombre del equipo (por ejemplo CONSULTORIO-1).',
        ]);

        $medico = Medico::findOrFail($this->medicoId);
        $reg = $medico->regMedicoPrincipal();
        if (! $reg) {
            $this->addError('equipo', 'Este médico no tiene reg_medico: no se le puede emitir una credencial.');

            return;
        }

        try {
            $r = $credenciales->emitir($reg, $this->equipo, (int) $this->anios, false, null, (bool) $this->reemplazar);
        } catch (DomainException $e) {
            $this->mostrarReemplazo = str_starts_with($e->getMessage(), 'Ya hay');
            $this->addError('equipo', $this->mostrarReemplazo
                ? 'Ese equipo ya tiene una credencial activa. Marca "Reemplazar" para revocarla y emitir una nueva.'
                : $e->getMessage());

            return;
        }

        $this->tokenGenerado = [
            'medico'     => trim($medico->name . ' ' . $medico->lastname),
            'reg_medico' => $reg,
            'equipo'     => $r['credencial']->machine_label,
            'vence'      => $r['credencial']->expires_at->format('d/m/Y'),
            'token'      => $r['token'],
            'revocadas'  => $r['revocadas'],
        ];
        $this->cerrarModal();
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

    public function cerrarToken()
    {
        $this->tokenGenerado = null;
    }
}
