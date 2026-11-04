<?php

namespace App\Http\Livewire\Admin;

use App\Actions\Medicos\AprobarSolicitudDeMedico;
use App\Actions\Medicos\RechazarSolicitudDeMedico;
use App\Models\ConfiguracionDeVerificacion;
use App\Models\Medico;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use App\Models\VerificacionDeMedico;
use App\Verificacion\VerificacionesDelMedico;
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
 *
 * **Verificación de médicos (2026-11-03)**: arriba vive el interruptor de la política
 * (`configuracion_verificacion_medicos.exigir_carnet`) y en el detalle de la solicitud, los tres estados del
 * perfil —carnet, correo y teléfono— con las acciones para dar el carnet por verificado o rechazarlo. Al
 * aprobar, una casilla confirma el carnet; si no se marca, queda pendiente o sin verificar.
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

    /** La política: ¿el registro exige el carnet verificado? Nace apagada. */
    public $exigir_carnet = false;

    /** En el formulario de aprobación: ¿el administrador revisó y da el carnet por verificado? */
    public $carnet_verificado = false;

    public function mount(): void
    {
        $this->autorizar();
        $this->exigir_carnet = ConfiguracionDeVerificacion::actual()->exigeCarnet();
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
        $this->carnet_verificado = false;
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->reset(['solicitudId', 'reg_medico', 'specialty_id', 'motivo', 'carnet_verificado']);
        $this->resetErrorBag();
    }

    /**
     * Enciende o apaga la exigencia del carnet. Apagada (el valor de fábrica) el médico entra solo al
     * confirmar su correo; encendida, el registro espera la aprobación y el carnet sin verificar corta el
     * acceso.
     */
    public function guardarPolitica(): void
    {
        $this->validate(['exigir_carnet' => ['boolean']]);

        ConfiguracionDeVerificacion::actual()->update(['exigir_carnet' => (bool) $this->exigir_carnet]);

        session()->flash('message', $this->exigir_carnet
            ? 'Listo: desde ahora el registro espera la aprobación y un médico sin el carnet verificado no puede usar la app.'
            : 'Listo: el médico entra solo al confirmar su correo; su carnet queda guardado como sin verificar (o pendiente si adjuntó la foto).');
    }

    /**
     * Marca el carnet de la ficha de la solicitud abierta. Es la validación posterior: sirve para aprobar sin
     * carnet y verificarlo después, y para rechazarlo (que corta el acceso aunque la exigencia esté apagada).
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

        $solicitud = SolicitudMedico::findOrFail($this->solicitudId);
        $medico = $solicitud->medico;

        abort_if($medico === null, 404, 'La solicitud todavía no tiene ficha de médico.');

        app(VerificacionesDelMedico::class)->marcar(
            $medico,
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
                (bool) $this->carnet_verificado,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'email' || $campo === 'medico' ? 'reg_medico' : $campo, $mensajes[0]);
            }

            return;
        }

        $this->claveGenerada = [
            'titulo' => match (true) {
                $r['yaTeniaCuenta'] => 'Aprobada: el médico ya tenía su cuenta',
                $r['clave'] === null => 'Aprobada: se habilitó su área médica',
                $r['enlazada'] => 'Aprobada: acceso a la ficha existente',
                default => 'Aprobada: médico creado',
            },
            'correo' => $r['medico']->email,
            'clave' => $r['clave'],
            'yaTeniaCuenta' => $r['yaTeniaCuenta'],
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

        // Las tres verificaciones de la ficha de la solicitud abierta (carnet, correo y teléfono). Para una
        // solicitud pendiente todavía no hay ficha: no se muestra el bloque.
        $verificaciones = $seleccionada?->medico
            ? app(VerificacionesDelMedico::class)->de($seleccionada->medico)
            : null;

        return view('livewire.admin.solicitudes-de-medicos', [
            'solicitudes' => SolicitudMedico::query()
                ->with(['especialidad', 'referente'])
                ->when($this->estado !== 'todas', fn ($query) => $query->where('estado', $this->estado))
                ->orderByDesc('created_at')
                ->paginate(15),
            'conteos' => $this->conteos(),
            'seleccionada' => $seleccionada,
            'fichaExistente' => $fichaExistente,
            'verificaciones' => $verificaciones,
            'especialidades' => Specialty::where('activo', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
