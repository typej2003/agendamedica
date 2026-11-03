<?php

namespace App\Http\Livewire\Admin;

use App\Clinica\Catalogos\MotivosDeCitaDelCatalogo;
use App\Http\Livewire\Admin\Concerns\EmiteApiKeys;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaCitaOnline;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaCuentasDeCobro;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaDatos;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaEspecialidades;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaMotivosDeCita;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaPlanes;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaRoles;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaSecretarias;
use App\Http\Livewire\Admin\DetalleDelMedico\GestionaSolicitudes;
use App\Models\Comentario;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\PreferenciaDeCobro;
use App\Models\RegMedicoServicio;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use App\Models\SyncCredential;
use App\Models\VerificacionDeMedico;
use App\Pagos\CuentasDeCobroDelMedico;
use App\Services\CuentaService;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use App\Services\SyncCredencialService;
use App\Services\SyncResumenService;
use App\Verificacion\VerificacionesDelMedico;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * **Detalle de un médico** (Usuarios › Médicos › Ver): todo lo que el panel sabe de él, en pestañas que ahora
 * **administran** sin salir de la ficha —roles, API keys, especialidades, plan, secretarías, solicitud de
 * registro, motivos de cita, cita online, métodos de pago y horarios—. Cada pestaña lee lo suyo recién cuando
 * se abre (no se arma nada de las demás), y las que dependen de que haya datos (Pagos, Comentarios) solo
 * aparecen si existen.
 *
 * El formulario de datos, el de roles y el de especialidades son los **mismos componentes Blade** que usan
 * Usuarios y Especialidades, para no duplicar campos ni reglas. «Editar» abre ese formulario acá mismo, en la
 * pestaña «Datos».
 *
 * Es administrativo: no hay datos clínicos (de los pacientes sólo se cuenta cuántos son). La pestaña activa viaja en
 * la URL (`?pestana=`). Como el resto del panel, `hydrate()` vuelve a comprobar el permiso en cada acción.
 */
class DetalleDelMedico extends Component
{
    use EmiteApiKeys;
    use GestionaDatos;
    use GestionaRoles;
    use GestionaEspecialidades;
    use GestionaPlanes;
    use GestionaSecretarias;
    use GestionaSolicitudes;
    use GestionaMotivosDeCita;
    use GestionaCitaOnline;
    use GestionaCuentasDeCobro;

    /** Las pestañas que siempre están: clave => [rótulo, icono]. */
    public const PESTANAS = [
        'datos' => ['Datos', 'bi-person-vcard'],
        'sedes' => ['Sedes', 'bi-geo-alt'],
        'horarios' => ['Horarios', 'bi-clock'],
        'roles' => ['Roles', 'bi-shield-lock'],
        'api-keys' => ['API Keys', 'bi-key'],
        'especialidades' => ['Especialidades', 'bi-heart-pulse'],
        'planes' => ['Planes', 'bi-card-checklist'],
        'metodos-pago' => ['Métodos de pago', 'bi-credit-card'],
        'motivos' => ['Motivos de cita', 'bi-calendar-check'],
        'cita-online' => ['Cita online', 'bi-globe2'],
        'secretarias' => ['Secretarias', 'bi-people'],
        'pacientes' => ['Pacientes', 'bi-person-lines-fill'],
    ];

    /** Las que dependen de que el médico tenga datos en otras secciones. */
    public const PESTANAS_CONDICIONALES = [
        'pagos' => ['Pagos', 'bi-cash-stack'],
        'solicitud' => ['Solicitud de registro', 'bi-person-badge'],
        'comentarios' => ['Soporte y Sugerencias', 'bi-chat-heart'],
    ];

    public Medico $medico;

    public $pestana = 'datos';

    protected $queryString = ['pestana' => ['except' => 'datos']];

    /** Ventana del emisor de API keys (`emitir` | `credenciales` | null). */
    public $modal = null;
    public $medicoId = null;

    /** ¿Se ve el listado de credenciales de la pestaña API Keys? */
    public $verCredenciales = false;

    public function hydrate()
    {
        $this->autorizar();
    }

    public function mount(Medico $medico)
    {
        $this->autorizar();
        $this->medico = $medico;
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function irA(string $pestana)
    {
        $this->pestana = $pestana;
        $this->cerrarFormulariosDelDetalle();

        match ($pestana) {
            'datos' => $this->cargarDatosDelMedico(),
            'roles' => $this->cargarRoles(),
            'especialidades' => $this->cargarEspecialidades(),
            'cita-online' => $this->cargarCitaOnline(),
            'metodos-pago' => $this->cargarCuentasDeCobro(),
            default => null,
        };
    }

    /** Cierra lo que estuviera abierto al cambiar de pestaña (no se guarda nada a medias). */
    private function cerrarFormulariosDelDetalle(): void
    {
        $this->editando = false;
        $this->cerrarPlan();
        $this->cerrarSecretaria();
        $this->cerrarSolicitud();
        $this->cerrarMotivo();
        $this->cerrarCuentaForm();
        $this->verCredenciales = false;
        $this->avisoEspecialidad = null;
        $this->resetErrorBag();
    }

    public function verCredencialesDeEsteMedico(): void
    {
        $this->verCredenciales = true;
    }

    public function revocarCredencial(int $credencialId, SyncCredencialService $credenciales): void
    {
        $credencial = SyncCredential::findOrFail($credencialId);

        abort_unless(in_array((string) $credencial->reg_medico, $this->registrosDelMedico(), true), 404);

        $credenciales->revocar($credencial);
        session()->flash('message', 'Credencial revocada: ese equipo ya no puede sincronizar.');
    }

    private function registrosDelMedico(): array
    {
        return $this->medico->registrosDeTrabajo();
    }

    /**
     * Marca el carnet de este médico (2026-11-03). Es la validación que no depende de que exista una solicitud:
     * sirve para los médicos del escritorio cuando el administrador enciende la exigencia, y para aprobar un
     * registro y verificarlo después.
     */
    public function marcarCarnet(string $estado): void
    {
        $estados = [
            VerificacionDeMedico::VERIFICADO,
            VerificacionDeMedico::RECHAZADO,
            VerificacionDeMedico::PENDIENTE,
        ];

        if (! in_array($estado, $estados, true)) {
            return;
        }

        app(VerificacionesDelMedico::class)->marcar(
            $this->medico,
            VerificacionDeMedico::CARNET,
            $estado,
            auth()->user(),
            $estado === VerificacionDeMedico::VERIFICADO ? null : 'Marca manual del administrador.',
        );

        session()->flash('message', match ($estado) {
            VerificacionDeMedico::VERIFICADO => 'Carnet verificado: el médico ya puede entrar.',
            VerificacionDeMedico::RECHAZADO => 'Carnet rechazado: se le corta el acceso al médico hasta que se revise.',
            default => 'Carnet marcado como pendiente de revisión.',
        });
    }

    public function cerrarModal(): void
    {
        $this->modal = null;
        $this->medicoId = null;
        $this->reemplazar = false;
        $this->mostrarReemplazo = false;
        $this->resetErrorBag();
    }

    public function cerrarToken(): void
    {
        $this->tokenGenerado = null;
    }

    public function render()
    {
        $this->medico->loadMissing(['user.roles', 'registros', 'specialties', 'subespecialidades']);

        $registros = $this->registrosDelMedico();
        $hay = $this->conDatos($registros);

        $pestanas = self::PESTANAS;
        foreach (self::PESTANAS_CONDICIONALES as $clave => $pestana) {
            if ($hay[$clave]) {
                $pestanas[$clave] = $pestana;
            }
        }
        if (! isset($pestanas[$this->pestana])) {
            $this->pestana = 'datos';
        }

        return view('livewire.admin.detalle-del-medico', [
            'medico' => $this->medico,
            'pestanas' => $pestanas,
            'cuenta' => $this->medico->user,
            'registros' => $registros,
            'datos' => $this->datosDe($this->pestana, $registros),
            // Médico de la ventana «Generar API key» (concern EmiteApiKeys).
            'seleccionado' => $this->medicoId ? Medico::find($this->medicoId) : null,
            // Formularios compartidos con Usuarios y Especialidades.
            'formatosDeReporte' => CuentaService::FORMATOS_DE_REPORTE,
            'rolesDisponibles' => $this->pestana === 'roles' ? app(CuentaService::class)->rolesDisponibles() : [],
            'catalogoEspecialidades' => $this->pestana === 'especialidades'
                ? Specialty::with(['subespecialidades' => fn ($q) => $q->where('activo', true)])
                    ->orderByDesc('activo')->orderBy('name')->get()
                : collect(),
            'bancosDeCobro' => $this->pestana === 'metodos-pago'
                ? (array) config('pagos.pago_movil.catalogo', [])
                : [],
            'especialidadesSolicitud' => $this->pestana === 'solicitud'
                ? Specialty::where('activo', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    /**
     * Qué pestañas condicionales tienen algo que mostrar: tres `exists` baratos.
     *
     * @param  array<int,string>  $registros
     * @return array{pagos:bool, solicitud:bool, comentarios:bool}
     */
    private function conDatos(array $registros): array
    {
        return [
            'pagos' => $registros !== [] && Pago::whereIn('reg_medico', $registros)->exists(),
            'solicitud' => SolicitudMedico::where('medico_id', $this->medico->id)->exists(),
            'comentarios' => Comentario::where('medico_id', $this->medico->id)->exists(),
        ];
    }

    /**
     * Lo que necesita la pestaña activa (y nada más).
     *
     * @param  array<int,string>  $registros
     * @return array<string,mixed>
     */
    private function datosDe(string $pestana, array $registros): array
    {
        return match ($pestana) {
            'datos' => $this->datos($registros),
            'sedes' => ['sedes' => app(\App\Services\Admin\SedesDelMedico::class)->sedes($this->medico, true)],
            'horarios' => $this->horarios(),
            'roles' => ['roles' => $this->medico->user?->roles->pluck('name')->sort()->values() ?? collect()],
            'api-keys' => ['sync' => $this->sync()],
            'especialidades' => [
                'especialidades' => $this->medico->specialties()->orderBy('name')->get(),
                'subespecialidades' => $this->medico->subespecialidades()->orderBy('nombre')->get(),
            ],
            'planes' => $this->planes($registros),
            'metodos-pago' => $this->metodosDePago(),
            'motivos' => $this->motivos(),
            'cita-online' => [],
            'secretarias' => ['secretarias' => app(CuentaService::class)->secretariasDe($registros)],
            'pacientes' => $this->pacientes($registros),
            'pagos' => ['pagos' => Pago::whereIn('reg_medico', $registros)->orderByDesc('id')->limit(15)->get(),
                        'total' => Pago::whereIn('reg_medico', $registros)->count()],
            'solicitud' => ['solicitudes' => SolicitudMedico::with('especialidad')->where('medico_id', $this->medico->id)->orderByDesc('id')->get()],
            'comentarios' => ['comentarios' => Comentario::where('medico_id', $this->medico->id)->orderByDesc('id')->limit(15)->get(),
                              'total' => Comentario::where('medico_id', $this->medico->id)->count()],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function datos(array $registros): array
    {
        $sync = $this->sync();

        $doctores = collect();
        if ($this->medico->es_centro && $this->medico->reg_medico) {
            $ids = MedicoRegistro::where('reg_medico', $this->medico->reg_medico)->pluck('medico_id')->filter()->unique()->all();
            $doctores = Medico::whereIn('id', $ids)->orderBy('name')->orderBy('lastname')->get();
        }

        return [
            'sync' => $sync,
            'doctoresDelCentro' => $doctores,
            // Las tres verificaciones del perfil (2026-11-03); se administran desde la bandeja de solicitudes.
            'verificaciones' => app(VerificacionesDelMedico::class)->de($this->medico),
        ];
    }

    /** El resumen de sincronización del médico (el mismo de «API Keys»), calculado una vez por petición. */
    private function sync(): array
    {
        return app(SyncResumenService::class)->resumir(collect([$this->medico]))[$this->medico->id] ?? [];
    }

    /** @return array<string,mixed> */
    private function planes(array $registros): array
    {
        $servicios = app(ServicioService::class);
        $actual = $registros === [] ? null : $servicios->actual((string) $this->medico->reg_medico);

        $planesActivos = in_array($this->planAccion, ['renovar', 'reemplazar'], true)
            ? Plan::where('activo', true)->orderBy('orden')->orderBy('id')->get()
            : collect();

        // Los planes que no cubren al equipo actual se marcan en el desplegable (salvo el que ya tiene).
        $planesNoCubren = [];
        if ($planesActivos->isNotEmpty() && $registros !== []) {
            $cupos = app(CupoDelPlan::class);

            foreach ($planesActivos as $plan) {
                if ($actual && (int) $actual->plan_id === (int) $plan->id) {
                    continue;
                }
                $motivo = $cupos->motivoNoCabe($plan, (string) $this->medico->reg_medico);
                if ($motivo !== null) {
                    $planesNoCubren[$plan->id] = $motivo;
                }
            }
        }

        $inicioRenovacion = $this->planAccion === 'renovar' && $registros !== []
            ? $servicios->inicioDeRenovacion((string) $this->medico->reg_medico)
            : null;

        return [
            'estados' => collect($registros)->map(fn ($reg) => $servicios->estado($reg))->all(),
            'historial' => $registros === [] ? collect() : RegMedicoServicio::whereIn('reg_medico', $registros)->orderByDesc('vence_el')->orderByDesc('id')->limit(10)->get(),
            'actual' => $actual,
            'planesActivos' => $planesActivos,
            'planesNoCubren' => $planesNoCubren,
            'inicioRenovacion' => $inicioRenovacion,
        ];
    }

    /** @return array<string,mixed> */
    private function horarios(): array
    {
        if ((string) $this->medico->reg_medico === '') {
            return ['consultorios' => collect()];
        }

        return [
            'consultorios' => Office::delMedico((string) $this->medico->reg_medico, $this->medico->id)
                ->with(['medicalCenter', 'schedules'])
                ->orderBy('medical_center_id')
                ->orderBy('office_number')
                ->get(),
            'nombresDeDias' => \App\Models\OfficeSchedule::DIAS,
        ];
    }

    /** @return array<string,mixed> */
    private function metodosDePago(): array
    {
        $reg = (string) $this->medico->reg_medico;

        return [
            'cuentasDeCobro' => app(CuentasDeCobroDelMedico::class)->de($reg),
            'recaudador' => PreferenciaDeCobro::de($reg),
        ];
    }

    /** @return array<string,mixed> */
    private function motivos(): array
    {
        $reg = (string) $this->medico->reg_medico;

        return [
            'motivos' => MotivoCita::with('office.medicalCenter')
                ->where('reg_medico', $reg)
                ->orderBy('codigo')
                ->orderBy('office_id')
                ->get(),
            'sedesDeLosMotivos' => app(MotivosDeCitaDelCatalogo::class)->sedes($reg, $this->medico->id),
        ];
    }

    /** Sólo conteos administrativos, nunca datos del paciente. @return array<string,mixed> */
    private function pacientes(array $registros): array
    {
        if ($registros === []) {
            return ['porRegistro' => collect(), 'historias' => 0, 'pacientes' => 0];
        }

        $porRegistro = DB::table('medico_pacientes')->whereIn('reg_medico', $registros)
            ->selectRaw('reg_medico, count(distinct paciente_id) as n')->groupBy('reg_medico')->pluck('n', 'reg_medico');

        return [
            'porRegistro' => collect($registros)->mapWithKeys(fn ($reg) => [$reg => (int) ($porRegistro[$reg] ?? 0)]),
            'pacientes' => (int) DB::table('medico_pacientes')->whereIn('reg_medico', $registros)->distinct()->count('paciente_id'),
            'historias' => (int) DB::table('historias')->whereIn('reg_medico', $registros)->count(),
        ];
    }
}
