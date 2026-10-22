<?php

namespace App\Http\Livewire\Admin;

use App\Http\Livewire\Admin\Concerns\EmiteApiKeys;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sección "Usuarios" del panel (Paso 26): ver administradores y médicos, darlos de alta con clave temporal,
 * editar sus datos, resetear claves, bloquear/desbloquear, cambiar sus roles y generar la API key de sync.
 * Cada fila tiene un botón "Editar" y un menú de 3 puntos con el resto de acciones.
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
    use EmiteApiKeys;

    protected $paginationTheme = 'bootstrap';

    public const PESTANAS = ['medicos', 'administradores'];

    // Listado
    public $pestana = 'medicos';
    public $search = '';

    // Qué ventana está abierta: alta | editar | acceso | reset | bloquear | roles | registros | emitir (API key) | null
    public $modal = null;

    // Formulario de alta / acceso
    public $tipoAlta = 'medico';
    public $name = '';
    public $lastname = '';
    public $prefix = '';
    public $email = '';
    public $reg_medico = '';
    public $phone = '';
    public $license_number = '';
    public $clave = '';

    /**
     * **Centro médico** (Fase 2 del cambio de `reg_medico`): una fila de `medicos` con `es_centro = 1` y su
     * propio `reg_medico`, sin cuenta de acceso. Es el tenant donde trabajan los doctores; se crea desde el
     * alta y se puede marcar o desmarcar al editar.
     */
    public $es_centro = false;

    /** Con qué membrete se imprimen los reportes del centro (`centro`/`medico`): en esta fase, sólo el campo. */
    public $formato_reportes = '';

    // Edición: de qué es la ficha que se edita ('medico' | 'administrador') y roles marcados en la ventana de roles
    public $tipoEdicion = 'medico';
    public $rolesSeleccionados = [];

    // Cuenta o médico sobre el que se actúa, y motivo de bloqueo
    public $userId = null;
    public $medicoId = null;
    public $motivo = '';

    // Ventana de registros de datos: el reg_medico elegido para asignar y el resultado de la última acción
    public $regNuevo = '';
    public $avisoRegistros = null;

    /** Doctor elegido para **vincularlo a un centro** (el `medicos.id` de la ficha del doctor). */
    public $doctorNuevo = '';

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
            $filas = Medico::with(['user.roles', 'registros'])
                ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('lastname', 'like', "%{$busqueda}%")
                    ->orWhere('email', 'like', "%{$busqueda}%")
                    ->orWhere('reg_medico', 'like', "%{$busqueda}%")))
                ->orderBy('name')
                ->orderBy('lastname')
                ->paginate(10);
        }

        return view('livewire.admin.cuentas', [
            'filas'         => $filas,
            // Para la ventana "Generar API key" (ver Concerns\EmiteApiKeys)
            'seleccionado'  => $this->modal === 'emitir' && $this->medicoId ? Medico::find($this->medicoId) : null,
            'rolesDisponibles' => $this->modal === 'roles' ? app(CuentaService::class)->rolesDisponibles() : [],
            'medicoRegistros'  => $this->modal === 'registros' && $this->medicoId ? Medico::find($this->medicoId) : null,
            // Los doctores ya vinculados al centro que se está mirando (Fase 2 del cambio de `reg_medico`).
            'doctoresDelCentro' => $this->modal === 'registros' ? $this->doctoresDelCentro() : collect(),
            'doctoresVinculables' => $this->modal === 'registros' ? $this->doctoresVinculables() : collect(),
            'formatosDeReporte' => CuentaService::FORMATOS_DE_REPORTE,
        ]);
    }

    /**
     * Los doctores ya vinculados al **centro** que se está editando en la ventana de registros: las fichas
     * de los `medico_registros` con ese `reg_medico`. Es lo que el centro tiene asociado hoy.
     *
     * @return \Illuminate\Support\Collection<int, Medico>
     */
    private function doctoresDelCentro()
    {
        $centro = $this->medicoId ? Medico::find($this->medicoId) : null;

        if ($centro === null || ! $centro->es_centro) {
            return collect();
        }

        $ids = MedicoRegistro::where('reg_medico', $centro->reg_medico)->pluck('medico_id')->filter()->unique()->all();

        return Medico::whereIn('id', $ids)->orderBy('name')->orderBy('lastname')->get();
    }

    /**
     * Los doctores **ya registrados** que se le pueden asociar al centro: los que no están vinculados a él y
     * no son centros (un centro no se vincula como doctor). Es la otra cara de `registrosAsignables()`.
     *
     * @return \Illuminate\Support\Collection<int, Medico>
     */
    private function doctoresVinculables()
    {
        $centro = $this->medicoId ? Medico::find($this->medicoId) : null;

        if ($centro === null || ! $centro->es_centro) {
            return collect();
        }

        $yaVinculados = MedicoRegistro::where('reg_medico', $centro->reg_medico)->pluck('medico_id')->filter()->unique()->all();

        return Medico::where('es_centro', false)
            ->whereNotIn('id', $yaVinculados)
            ->whereNotNull('reg_medico')
            ->orderBy('name')
            ->orderBy('lastname')
            ->get();
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
            } elseif ($this->es_centro) {
                // **El centro no tiene cuenta**: sólo la ficha del tenant, con su `reg_medico`. No se pide
                // correo ni clave (el correo es opcional), y no aparece en la lista de cuentas.
                $this->validate([
                    'name'       => 'required|string|max:255',
                    'lastname'   => 'required|string|max:255',
                    'prefix'     => 'nullable|string|max:20',
                    'email'      => 'nullable|email|max:255',
                    'reg_medico' => 'nullable|string|max:100',
                ]);
                $centro = $cuentas->crearCentro([
                    'name'       => $this->name,
                    'lastname'   => $this->lastname,
                    'prefix'     => $this->prefix,
                    'email'      => $this->email,
                    'reg_medico' => $this->reg_medico,
                ]);

                session()->flash('message', "Centro médico creado (reg_medico {$centro->reg_medico}). No tiene cuenta de acceso: "
                    . 'asóciale sus doctores desde «Registros de datos».');
                $this->pestana = 'medicos';
                $this->cerrarModal();

                return;
            } else {
                $this->validate([
                    'name'       => 'required|string|max:255',
                    'lastname'   => 'required|string|max:255',
                    'prefix'     => 'nullable|string|max:20',
                    'email'      => 'required|email|max:255',
                    'reg_medico' => 'nullable|string|max:100',
                ]);
                $r = $cuentas->crearMedico([
                    'name'       => $this->name,
                    'lastname'   => $this->lastname,
                    'prefix'     => $this->prefix,
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
    /* Edición                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param  string  $tipo  'medico' (por id de ficha, desde la pestaña Médicos) o 'administrador' (por id de cuenta)
     */
    public function abrirEditar(string $tipo, int $id)
    {
        $this->limpiarFormulario();

        if ($tipo === 'administrador') {
            $user = User::findOrFail($id);
            $this->tipoEdicion = 'administrador';
            $this->userId = $user->id;
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
        } else {
            $medico = Medico::findOrFail($id);
            $this->tipoEdicion = 'medico';
            $this->medicoId = $medico->id;
            $this->name = (string) $medico->name;
            $this->lastname = (string) $medico->lastname;
            $this->prefix = (string) $medico->prefix;
            $this->email = (string) $medico->email;
            $this->phone = (string) $medico->phone;
            $this->license_number = (string) $medico->license_number;
            // Sólo para mostrarlo: no se edita (es la llave de todos sus datos clínicos en la nube).
            $this->reg_medico = (string) $medico->reg_medico;
            // ¿Es un centro médico y con qué membrete imprime sus reportes? (Fase 2 del cambio de `reg_medico`).
            $this->es_centro = (bool) $medico->es_centro;
            $this->formato_reportes = (string) $medico->formato_reportes;
        }

        $this->modal = 'editar';
    }

    public function guardarEdicion(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            if ($this->tipoEdicion === 'administrador') {
                $this->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255']);
                $cuentas->actualizarUsuario(User::findOrFail($this->userId), ['name' => $this->name, 'email' => $this->email]);
            } else {
                $this->validate([
                    'name'           => 'required|string|max:255',
                    'lastname'       => 'required|string|max:255',
                    'prefix'         => 'nullable|string|max:20',
                    // Un centro médico no inicia sesión: su correo es opcional (Fase 2 del cambio de `reg_medico`).
                    'email'          => ($this->es_centro ? 'nullable' : 'required') . '|email|max:255',
                    'phone'          => 'nullable|string|max:50',
                    'license_number' => 'nullable|string|max:100',
                ]);
                $cuentas->actualizarMedico(Medico::findOrFail($this->medicoId), [
                    'name'           => $this->name,
                    'lastname'       => $this->lastname,
                    'prefix'         => $this->prefix,
                    'email'          => $this->email,
                    'phone'          => $this->phone,
                    'license_number' => $this->license_number,
                ]);

                // El check de **centro médico** y el formato de sus reportes (Fase 2 del cambio de
                // `reg_medico`). El formato sólo tiene sentido en un centro, pero se guarda igual lo que
                // venga: convertirlo en centro después no debería perderlo.
                $this->validate(['formato_reportes' => 'nullable|in:' . implode(',', CuentaService::FORMATOS_DE_REPORTE)]);
                $cuentas->configurarCentro(
                    Medico::findOrFail($this->medicoId),
                    (bool) $this->es_centro,
                    $this->formato_reportes ?: null,
                );
            }

            session()->flash('message', 'Datos actualizados.');
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

    /* ------------------------------------------------------------------ */
    /* Roles                                                               */
    /* ------------------------------------------------------------------ */

    public function abrirRoles(int $userId)
    {
        $user = User::findOrFail($userId);
        $this->limpiarFormulario();
        $this->userId = $user->id;
        $this->rolesSeleccionados = $user->roles()->pluck('name')->all();
        $this->modal = 'roles';
    }

    public function guardarRoles(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $cuentas->asignarRoles(User::findOrFail($this->userId), (array) $this->rolesSeleccionados, auth()->user());
            session()->flash('message', 'Roles actualizados.');
            $this->cerrarModal();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Registros de datos (reg_medico)                                     */
    /* ------------------------------------------------------------------ */

    public function abrirRegistros(int $medicoId)
    {
        $medico = Medico::findOrFail($medicoId);
        $this->limpiarFormulario();
        $this->medicoId = $medico->id;
        $this->modal = 'registros';
    }

    public function asignarRegistro(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $this->avisoRegistros = null;
            // **No copia pacientes**: el vínculo es del consultorio y el médico los ve por el `reg_medico`
            // que acaba de recibir (Fase 1 del cambio de `reg_medico`).
            $cuentas->asignarRegistro(Medico::findOrFail($this->medicoId), (string) $this->regNuevo);
            $this->avisoRegistros = "Registro {$this->regNuevo} asignado: el médico ve sus pacientes y su agenda.";
            $this->regNuevo = '';
        });
    }

    public function quitarRegistro(string $regMedico, CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($regMedico, $cuentas) {
            $this->avisoRegistros = null;
            $cuentas->quitarRegistro(Medico::findOrFail($this->medicoId), $regMedico);
            $this->avisoRegistros = "Registro {$regMedico} quitado.";
        });
    }

    /**
     * **Desvincular a un doctor del centro** (Fase 2 del cambio de `reg_medico`): es la otra cara de
     * `asignarRegistro`. Se quita del doctor la fila de `medico_registros` con el `reg_medico` del centro —
     * nada se borra— y el doctor deja de ver el centro en su contexto.
     */
    public function quitarDoctorDelCentro(int $medicoId, CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($medicoId, $cuentas) {
            $this->avisoRegistros = null;
            $centro = Medico::findOrFail($this->medicoId);
            $cuentas->quitarRegistro(Medico::findOrFail($medicoId), (string) $centro->reg_medico);
            $this->avisoRegistros = 'Doctor desvinculado del centro.';
        });
    }

    /**
     * **Vincular al centro un doctor ya registrado** (Fase 2 del cambio de `reg_medico`): el centro asocia
     * doctores que ya existen, y lo que se escribe es la fila de `medico_registros` **del doctor** con el
     * `reg_medico` del centro. El doctor pasa a ver el centro en su contexto (y sus pacientes, en la lista
     * global); el centro no gana ninguna cuenta.
     */
    public function vincularDoctorAlCentro(CuentaService $cuentas)
    {
        $this->ejecutar(function () use ($cuentas) {
            $this->avisoRegistros = null;
            $centro = Medico::findOrFail($this->medicoId);
            $doctor = Medico::findOrFail((int) $this->doctorNuevo);

            if ($doctor->es_centro) {
                throw ValidationException::withMessages(['doctorNuevo' => 'Un centro médico no se vincula como doctor.']);
            }

            $cuentas->asignarRegistro($doctor, (string) $centro->reg_medico);
            $this->avisoRegistros = 'Doctor vinculado al centro.';
            $this->doctorNuevo = '';
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
        $this->reset(['name', 'lastname', 'prefix', 'email', 'reg_medico', 'phone', 'license_number', 'clave', 'userId', 'medicoId', 'motivo', 'rolesSeleccionados', 'regNuevo', 'avisoRegistros', 'es_centro', 'formato_reportes', 'doctorNuevo']);
        $this->tipoAlta = 'medico';
        $this->tipoEdicion = 'medico';
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
