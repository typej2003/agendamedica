<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "Usuarios" del panel (Paso 26): ver administradores y médicos, darlos de alta con clave temporal,
 * resetear claves, bloquear/desbloquear y sumar o quitar el rol Administrador.
 *
 * Toda la lógica de cuentas vive en `CuentaService`; acá solo hay pantalla. Las claves en claro se muestran UNA
 * sola vez (`$claveGenerada`) y no se guardan en ningún lado.
 *
 * Seguridad: la ruta ya exige Root o Administrador, pero cada acción de Livewire es una petición aparte que
 * no pasa por esa ruta, por eso `hydrate()` lo comprueba en TODAS.
 */
class Cuentas extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public const PESTANAS = ['medicos', 'administradores'];

    // Listado
    public $pestana = 'medicos';
    public $search = '';

    // Qué ventana está abierta: alta | reset | bloquear | acceso | quitar-admin | null
    public $modal = null;

    // Formulario de alta / acceso
    public $tipoAlta = 'medico';
    public $name = '';
    public $lastname = '';
    public $email = '';
    public $reg_medico = '';
    public $clave = '';

    // Cuenta o médico sobre el que se actúa, y motivo de bloqueo
    public $userId = null;
    public $medicoId = null;
    public $motivo = '';

    /** Se muestra una sola vez tras crear una cuenta o resetear una clave: ['titulo','correo','clave']. */
    public $claveGenerada = null;

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
        $this->resetPage();
    }

    public function updatedPestana($valor)
    {
        if (! in_array($valor, self::PESTANAS, true)) {
            $this->pestana = 'medicos';
        }
        $this->resetPage();
    }

    public function render()
    {
        $busqueda = trim($this->search);

        if ($this->pestana === 'administradores') {
            $filas = User::administradores()
                ->with(['roles', 'medico'])
                ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('email', 'like', "%{$busqueda}%")))
                ->orderBy('name')
                ->paginate(10);
        } else {
            $filas = Medico::with(['user.roles'])
                ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('lastname', 'like', "%{$busqueda}%")
                    ->orWhere('email', 'like', "%{$busqueda}%")
                    ->orWhere('reg_medico', 'like', "%{$busqueda}%")))
                ->orderBy('name')
                ->orderBy('lastname')
                ->paginate(10);
        }

        return view('livewire.admin.cuentas', ['filas' => $filas]);
    }

    /* ------------------------------------------------------------------ */
    /* Alta                                                                */
    /* ------------------------------------------------------------------ */

    public function abrirAlta(string $tipo)
    {
        $this->limpiarFormulario();
        $this->tipoAlta = $tipo === 'administrador' ? 'administrador' : 'medico';
        $this->modal = 'alta';
    }

    public function guardarAlta(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            if ($this->tipoAlta === 'administrador') {
                $this->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255']);
                $r = $cuentas->crearAdministrador($this->name, $this->email, $this->clave ?: null);
                $titulo = 'Administrador creado';
            } else {
                $this->validate([
                    'name'       => 'required|string|max:255',
                    'lastname'   => 'required|string|max:255',
                    'email'      => 'required|email|max:255',
                    'reg_medico' => 'required|string|max:100',
                ]);
                $r = $cuentas->crearMedico([
                    'name'       => $this->name,
                    'lastname'   => $this->lastname,
                    'email'      => $this->email,
                    'reg_medico' => $this->reg_medico,
                ], $this->clave ?: null);
                $titulo = 'Médico creado';
            }

            $this->mostrarClave($titulo, $r['user']->email, $r['clave']);
            $this->pestana = $this->tipoAlta === 'administrador' ? 'administradores' : 'medicos';
            $this->cerrarModal();
        });
    }

    /** Médico que existe en `medicos` pero no puede entrar: se le crea la cuenta con clave temporal. */
    public function abrirAcceso(int $medicoId)
    {
        $medico = Medico::findOrFail($medicoId);
        $this->limpiarFormulario();
        $this->medicoId = $medico->id;
        $this->email = (string) $medico->email;
        $this->modal = 'acceso';
    }

    public function guardarAcceso(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $medico = Medico::findOrFail($this->medicoId);
            $r = $cuentas->darAccesoAMedico($medico, $this->clave ?: null);
            $this->mostrarClave('Acceso creado', $r['user']->email, $r['clave']);
            $this->cerrarModal();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Reseteo, bloqueo y roles                                            */
    /* ------------------------------------------------------------------ */

    public function abrirReset(int $userId)
    {
        $this->limpiarFormulario();
        $this->userId = User::findOrFail($userId)->id;
        $this->modal = 'reset';
    }

    public function confirmarReset(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $user = User::findOrFail($this->userId);
            $clave = $cuentas->resetearClave($user, $this->clave ?: null);
            $this->mostrarClave('Clave restablecida', $user->email, $clave);
            $this->cerrarModal();
        });
    }

    public function abrirBloqueo(int $userId)
    {
        $this->limpiarFormulario();
        $this->userId = User::findOrFail($userId)->id;
        $this->modal = 'bloquear';
    }

    public function confirmarBloqueo(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $this->validate(['motivo' => 'nullable|string|max:255']);
            $cuentas->bloquear(User::findOrFail($this->userId), (string) $this->motivo, auth()->user());
            session()->flash('message', 'Cuenta bloqueada. Se cerraron sus sesiones.');
            $this->cerrarModal();
        });
    }

    public function desbloquear(int $userId, CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($userId, $cuentas) {
            $cuentas->desbloquear(User::findOrFail($userId));
            session()->flash('message', 'Cuenta desbloqueada.');
        });
    }

    public function hacerAdministrador(int $userId, CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($userId, $cuentas) {
            $cuentas->hacerAdministrador(User::findOrFail($userId));
            session()->flash('message', 'Ahora es administrador. Conserva sus otros roles.');
        });
    }

    public function abrirQuitarAdmin(int $userId)
    {
        $this->limpiarFormulario();
        $this->userId = User::findOrFail($userId)->id;
        $this->modal = 'quitar-admin';
    }

    public function confirmarQuitarAdmin(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $cuentas->quitarAdministrador(User::findOrFail($this->userId), auth()->user());
            session()->flash('message', 'Se quitó el rol de administrador.');
            $this->cerrarModal();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Ventanas                                                            */
    /* ------------------------------------------------------------------ */

    public function cerrarModal()
    {
        $this->modal = null;
        $this->limpiarFormulario();
    }

    public function cerrarClave()
    {
        $this->claveGenerada = null;
    }

    private function mostrarClave(string $titulo, string $correo, string $clave): void
    {
        $this->claveGenerada = ['titulo' => $titulo, 'correo' => $correo, 'clave' => $clave];
    }

    private function limpiarFormulario(): void
    {
        $this->reset(['name', 'lastname', 'email', 'reg_medico', 'clave', 'userId', 'medicoId', 'motivo']);
        $this->tipoAlta = 'medico';
        $this->resetErrorBag();
    }

    /**
     * Los errores de reglas de negocio de `CuentaService` (correo repetido, último administrador…) son
     * `ValidationException`; se muestran en la ventana abierta o, si no hay, en el aviso de la página.
     */
    private function ejecutar(callable $accion): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }
        }
    }
}
