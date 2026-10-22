<?php

namespace App\Http\Livewire\Admin;

use App\Models\User;
use App\Paciente\BloqueoDePacientes;
use App\Paciente\NivelesDeIdentidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Usuarios › Pacientes**: las cuentas que los pacientes crean por su cuenta (rol `Paciente`, ficha enlazada por
 * `pacientes.user_id`) y con las que agendan por «Mis citas», la reserva pública o el chatbot. Es **sólo lectura**
 * —sin datos clínicos— más una acción de emergencia: bloquear y desbloquear (abuso, spam, reservas falsas), con
 * motivo opcional y auditoría (`BloqueoDePacientes`). No se borra ninguna cuenta.
 *
 * La ruta ya exige Root o Administrador; `hydrate()` lo vuelve a comprobar en cada acción de Livewire.
 */
class UsuariosPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    /** Filtro de estado: todos | activos | bloqueados. */
    public $estado = 'todos';

    /** Ventana abierta: `bloquear` | `desbloquear` | null, sobre la cuenta `$userId`. */
    public $modal = null;
    public $userId = null;
    public $motivo = '';

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

    public function updatingEstado()
    {
        $this->resetPage();
    }

    public function abrir(string $que, int $userId)
    {
        abort_unless(in_array($que, ['bloquear', 'desbloquear'], true), 404);
        $this->resetErrorBag();
        $this->modal = $que;
        $this->userId = $userId;
        $this->motivo = '';
    }

    public function cerrarModal()
    {
        $this->reset(['modal', 'userId', 'motivo']);
        $this->resetErrorBag();
    }

    public function confirmarBloqueo(BloqueoDePacientes $bloqueo)
    {
        $this->validate(['motivo' => 'nullable|string|max:255']);

        try {
            $bloqueo->bloquear(User::findOrFail($this->userId), (string) $this->motivo, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('cuenta', $e->validator->errors()->first());

            return;
        }

        session()->flash('message', 'Cuenta bloqueada: no podrá iniciar sesión ni reservar citas por su cuenta.');
        $this->cerrarModal();
    }

    public function confirmarDesbloqueo(BloqueoDePacientes $bloqueo)
    {
        try {
            $bloqueo->desbloquear(User::findOrFail($this->userId), auth()->user());
        } catch (ValidationException $e) {
            $this->addError('cuenta', $e->validator->errors()->first());

            return;
        }

        session()->flash('message', 'Cuenta desbloqueada: el paciente vuelve a poder reservar.');
        $this->cerrarModal();
    }

    public function render(BloqueoDePacientes $bloqueo, NivelesDeIdentidad $niveles)
    {
        $seleccionado = $this->modal && $this->userId ? User::with('paciente')->find($this->userId) : null;

        $busqueda = trim($this->search);

        $filas = User::role('Paciente')
            ->with('paciente:id,user_id,cedula,nombres,apellidos,telefono,email')
            ->when($this->estado === 'activos', fn ($q) => $q->where('is_active', true))
            ->when($this->estado === 'bloqueados', fn ($q) => $q->where('is_active', false))
            ->when($busqueda !== '', function ($q) use ($busqueda) {
                $q->where(function ($w) use ($busqueda) {
                    $w->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('email', 'like', "%{$busqueda}%")
                        ->orWhereHas('paciente', fn ($p) => $p
                            ->where('cedula', 'like', "%{$busqueda}%")
                            ->orWhere('telefono', 'like', "%{$busqueda}%")
                            ->orWhere('nombres', 'like', "%{$busqueda}%")
                            ->orWhere('apellidos', 'like', "%{$busqueda}%"));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.admin.usuarios-pacientes', [
            'filas' => $filas,
            'citas' => $this->citasDeLaPagina($filas->getCollection()),
            'seleccionado' => $seleccionado,
            // Qué se bloqueará según el nivel de identidad de la cuenta (ver `BloqueoDePacientes`).
            'alcances' => $seleccionado ? $bloqueo->alcancesDe($seleccionado) : null,
            'niveles' => $filas->getCollection()->mapWithKeys(fn ($fila) => [$fila->id => $niveles->nivel($fila)])->all(),
        ]);
    }

    /**
     * Cuántas citas ha creado cada cuenta de la página y la fecha de la última: en dos consultas para toda la
     * página (no una por fila). Una cita es del paciente por `cola.paciente_sinhistoria_id` o por el vínculo
     * `medico_pacientes` (`reg_medico` + `numhistoria`); se cuentan sin repetir.
     *
     * @return array<int,array{total:int,ultima:?string}> por `pacientes.id`
     */
    private function citasDeLaPagina($usuarios): array
    {
        $ids = $usuarios->pluck('paciente.id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $sinHistoria = DB::table('cola')
            ->whereIn('paciente_sinhistoria_id', $ids)
            ->get(['id', 'fecha', 'paciente_sinhistoria_id as paciente_id']);

        $conHistoria = DB::table('medico_pacientes as mp')
            ->join('cola as c', function ($j) {
                $j->on('c.reg_medico', '=', 'mp.reg_medico')->on('c.numhistoria', '=', 'mp.numhistoria');
            })
            ->whereIn('mp.paciente_id', $ids)
            ->get(['c.id', 'c.fecha', 'mp.paciente_id']);

        $porPaciente = [];
        foreach ($sinHistoria->concat($conHistoria) as $cita) {
            $porPaciente[(int) $cita->paciente_id][(int) $cita->id] = $cita->fecha;
        }

        $resumen = [];
        foreach ($porPaciente as $pacienteId => $fechas) {
            $resumen[$pacienteId] = ['total' => count($fechas), 'ultima' => collect($fechas)->filter()->max()];
        }

        return $resumen;
    }
}
