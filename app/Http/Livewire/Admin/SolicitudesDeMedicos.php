<?php

namespace App\Http\Livewire\Admin;

use App\Actions\Medicos\AprobarSolicitudDeMedico;
use App\Actions\Medicos\RechazarSolicitudDeMedico;
use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Bandeja de solicitudes de médicos** (ROADMAP §10.4, fase 2): las que llegan de `/registro/medico`, para
 * aprobarlas o rechazarlas.
 *
 * Abre en **pendientes**. Al aprobar se confirma el `reg_medico` (propone `MPPS-<número>`; si ya existe una
 * ficha sin cuenta con ese registro, se le da acceso a ella) y la especialidad. La clave temporal se muestra una
 * vez, como en `Cuentas`, además de ir por correo. El permiso se vuelve a comprobar en cada acción (`hydrate`).
 */
class SolicitudesDeMedicos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /** `pendiente` | `aprobada` | `rechazada` | `todas`. */
    public $estado = SolicitudMedico::PENDIENTE;

    public $solicitudId = null;

    public $reg_medico = '';

    public $specialty_id = '';

    public $motivo = '';

    /** La clave de la última aprobación, para mostrarla una sola vez. */
    public $claveGenerada = null;

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

    public function updatingEstado(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function abrir(int $solicitudId): void
    {
        $solicitud = SolicitudMedico::findOrFail($solicitudId);
        $this->solicitudId = $solicitud->id;
        $this->reg_medico = $solicitud->reg_medico ?: $solicitud->regMedicoPropuesto();
        $this->specialty_id = (string) ($solicitud->specialty_id ?? '');
        $this->motivo = (string) $solicitud->motivo_rechazo;
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->reset(['solicitudId', 'reg_medico', 'specialty_id', 'motivo']);
        $this->resetErrorBag();
    }

    public function aprobar(AprobarSolicitudDeMedico $aprobar): void
    {
        $this->validate(
            ['reg_medico' => ['required', 'string', 'max:100'], 'specialty_id' => ['nullable', 'integer']],
            ['reg_medico.required' => 'Escribe el reg_medico con el que va a trabajar.'],
        );

        try {
            $r = $aprobar->ejecutar(
                SolicitudMedico::findOrFail($this->solicitudId),
                auth()->user(),
                $this->reg_medico,
                $this->specialty_id !== '' ? (int) $this->specialty_id : null,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'email' || $campo === 'medico' ? 'reg_medico' : $campo, $mensajes[0]);
            }

            return;
        }

        $this->claveGenerada = [
            'titulo' => $r['enlazada'] ? 'Aprobada: acceso a la ficha existente' : 'Aprobada: médico creado',
            'correo' => $r['medico']->email,
            'clave' => $r['clave'],
            'correoEnviado' => $r['correoEnviado'],
        ];
        $this->cerrar();
    }

    public function rechazar(RechazarSolicitudDeMedico $rechazar): void
    {
        $this->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:2000']],
            [
                'motivo.required' => 'Escribe el motivo: es lo que le llega al médico.',
                'motivo.min' => 'El motivo es muy corto (al menos 5 caracteres).',
            ],
        );

        try {
            $enviado = $rechazar->ejecutar(SolicitudMedico::findOrFail($this->solicitudId), auth()->user(), $this->motivo);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }

            return;
        }

        session()->flash('message', $enviado
            ? 'Solicitud rechazada: le avisamos al médico por correo con el motivo.'
            : 'Solicitud rechazada, pero el correo no salió: avísale al médico por otro medio.');
        $this->cerrar();
    }

    public function cerrarClave(): void
    {
        $this->claveGenerada = null;
    }

    /** @return array<string,int> */
    public function conteos(): array
    {
        $conteos = SolicitudMedico::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        foreach (SolicitudMedico::ESTADOS as $estado) {
            $conteos[$estado] = (int) ($conteos[$estado] ?? 0);
        }

        return $conteos;
    }

    public function render()
    {
        $seleccionada = $this->solicitudId
            ? SolicitudMedico::with(['especialidad', 'revisor', 'medico'])->find($this->solicitudId)
            : null;

        // Si el reg_medico escrito ya es una ficha, se avisa qué va a pasar antes de aprobar.
        $fichaExistente = $seleccionada && trim((string) $this->reg_medico) !== ''
            ? Medico::where('reg_medico', trim((string) $this->reg_medico))->first()
            : null;

        return view('livewire.admin.solicitudes-de-medicos', [
            'solicitudes' => SolicitudMedico::query()
                ->with('especialidad')
                ->when($this->estado !== 'todas', fn ($query) => $query->where('estado', $this->estado))
                ->orderByDesc('created_at')
                ->paginate(15),
            'conteos' => $this->conteos(),
            'seleccionada' => $seleccionada,
            'fichaExistente' => $fichaExistente,
            'especialidades' => Specialty::where('activo', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
