<?php

namespace App\Http\Livewire\Admin;

use App\Models\Comentario;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Pago;
use App\Models\RegMedicoServicio;
use App\Models\SolicitudMedico;
use App\Services\Admin\SedesDelMedico;
use App\Services\CuentaService;
use App\Services\ServicioService;
use App\Services\SyncResumenService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * **Detalle de un médico** (Usuarios › Médicos › Ver): todo lo que el panel sabe de él, en pestañas **de sólo
 * lectura**. Cada pestaña lee lo suyo recién cuando se abre (no se arma nada de las demás), y las que dependen de
 * que haya datos (Pagos, Solicitud de registro, Comentarios) solo aparecen si existen. Cada una enlaza a la
 * pantalla donde ese dato se administra; «Editar» abre el diálogo de edición que ya tiene Médicos.
 *
 * Es administrativo: no hay datos clínicos (de los pacientes sólo se cuenta cuántos son). La pestaña activa viaja en
 * la URL (`?pestana=`). Como el resto del panel, `hydrate()` vuelve a comprobar el permiso en cada acción.
 */
class DetalleDelMedico extends Component
{
    /** Las pestañas que siempre están: clave => [rótulo, icono]. */
    public const PESTANAS = [
        'datos' => ['Datos', 'bi-person-vcard'],
        'sedes' => ['Sedes', 'bi-geo-alt'],
        'roles' => ['Roles', 'bi-shield-lock'],
        'api-keys' => ['API Keys', 'bi-key'],
        'especialidades' => ['Especialidades', 'bi-heart-pulse'],
        'planes' => ['Planes', 'bi-card-checklist'],
        'secretarias' => ['Secretarias', 'bi-people'],
        'pacientes' => ['Pacientes', 'bi-person-lines-fill'],
    ];

    /** Las que dependen de que el médico tenga datos en otras secciones. */
    public const PESTANAS_CONDICIONALES = [
        'pagos' => ['Pagos', 'bi-cash-stack'],
        'solicitud' => ['Solicitud de registro', 'bi-person-badge'],
        'comentarios' => ['Ayúdanos a mejorar', 'bi-chat-heart'],
    ];

    public Medico $medico;

    public $pestana = 'datos';

    protected $queryString = ['pestana' => ['except' => 'datos']];

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
    }

    public function render()
    {
        $this->medico->loadMissing(['user.roles', 'registros']);

        $registros = $this->medico->registrosDeTrabajo();
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
            'sedes' => ['sedes' => app(SedesDelMedico::class)->sedes($this->medico, true)],
            'roles' => ['roles' => $this->medico->user?->roles->pluck('name')->sort()->values() ?? collect()],
            'api-keys' => ['sync' => $this->sync()],
            'especialidades' => [
                'especialidades' => $this->medico->specialties()->orderBy('name')->get(),
                'subespecialidades' => $this->medico->subespecialidades()->orderBy('nombre')->get(),
            ],
            'planes' => $this->planes($registros),
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

        return ['sync' => $sync, 'doctoresDelCentro' => $doctores];
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

        return [
            'estados' => collect($registros)->map(fn ($reg) => $servicios->estado($reg))->all(),
            'historial' => $registros === [] ? collect() : RegMedicoServicio::whereIn('reg_medico', $registros)->orderByDesc('vence_el')->orderByDesc('id')->limit(10)->get(),
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
