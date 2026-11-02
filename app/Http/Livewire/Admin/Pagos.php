<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Pagos\Admin\AccionesDelPago;
use App\Pagos\Admin\PendientesPorMedico;
use App\Pagos\Cobros;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * **Pagos** (`/admin/pagos`): lo que entró por SyPago, con su estado, su bitácora y la conciliación manual.
 *
 * Es la pantalla de soporte del cobro: el admin ve si un pago quedó pendiente, en proceso o rechazado, qué
 * avisó el webhook y qué respondió SyPago, y puede forzar una consulta sin esperar al `pagos:conciliar`.
 *
 * No muestra secretos: ni el `operation_secret`, ni tokens, ni el OTP. La respuesta cruda y los payloads
 * de la bitácora se limpian antes de pintarse.
 *
 * Como `Servicios` y `MetodosPago`, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire.
 */
class Pagos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $estado = 'todos';
    public $canal = 'todos';
    public $search = '';

    /** El pago abierto en el detalle, si hay uno. */
    public $pagoId = null;

    /**
     * El motivo de la acción que se está confirmando (O-53). Es obligatorio para rechazar y para reembolsar;
     * opcional para dar por pagado.
     */
    public $motivo = '';

    /** El pago sobre el que se va a actuar y qué acción se está confirmando (`aprobar`, `rechazar`, `reembolsar`). */
    public $accionId = null;
    public $accion = '';

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

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function updatingEstado(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function updatingCanal(): void
    {
        $this->resetPage();
        $this->cerrar();
    }

    public function abrir(int $pagoId): void
    {
        $this->pagoId = Pago::findOrFail($pagoId)->id;
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->pagoId = null;
        $this->resetErrorBag();
        $this->cerrarAccion();
    }

    // --------------------------------------------------------------------- Administrar un pago (O-53)

    /**
     * **Empezar una acción sobre un pago**: dar por pagado, rechazar o reembolsar. La pantalla pide el motivo
     * (obligatorio en los dos últimos) y recién después se aplica.
     */
    public function administrar(int $pagoId, string $accion): void
    {
        $pago = Pago::findOrFail($pagoId);

        $this->accionId = $pago->id;
        $this->accion = in_array($accion, ['aprobar', 'rechazar', 'reembolsar'], true) ? $accion : '';
        $this->motivo = '';
        $this->resetErrorBag();

        // El pago tiene que poder recibir esa acción: se avisa antes de que el admin escriba el motivo.
        $this->validarAccionPosible($pago, $this->accion);
    }

    public function cerrarAccion(): void
    {
        $this->accionId = null;
        $this->accion = '';
        $this->motivo = '';
        $this->resetErrorBag();
    }

    /**
     * **Aplicar la acción confirmada.** Todo el criterio vive en `AccionesDelPago`; acá sólo se valida lo que
     * es de la pantalla (el motivo) y se traduce el error a un aviso.
     */
    public function aplicarAccion(AccionesDelPago $acciones): void
    {
        $pago = Pago::findOrFail((int) $this->accionId);
        $usuario = auth()->user();

        $this->validarAccionPosible($pago, $this->accion);

        $motivo = trim((string) $this->motivo);

        if (in_array($this->accion, ['rechazar', 'reembolsar'], true) && $motivo === '') {
            $this->addError('motivo', 'Escribe el motivo: queda en la bitácora del pago.');

            return;
        }

        try {
            match ($this->accion) {
                'aprobar' => $acciones->marcarPagado($pago, $usuario, $motivo),
                'rechazar' => $acciones->rechazar($pago, $usuario, $motivo),
                'reembolsar' => $acciones->reembolsar($pago, $usuario, $motivo),
            };
        } catch (\RuntimeException $e) {
            $this->addError('motivo', $e->getMessage());

            return;
        }

        session()->flash('message', match ($this->accion) {
            'aprobar' => 'Pago dado por pagado.',
            'rechazar' => 'Pago rechazado.',
            'reembolsar' => 'Pago reembolsado.',
        });

        $pago->refresh();
        $this->cerrarAccion();
    }

    /** ¿La acción tiene sentido para el estado del pago? Si no, se dice antes de pedir el motivo. */
    private function validarAccionPosible(Pago $pago, string $accion): void
    {
        if (! $this->administrable($pago)) {
            $this->addError('motivo', 'Desde acá sólo se administran los pagos de citas. Los planes se aprueban contra la pasarela.');

            return;
        }

        $estado = $pago->estado;

        $posible = match ($accion) {
            'aprobar' => in_array($estado, PendientesPorMedico::ESTADOS, true),
            'rechazar' => in_array($estado, PendientesPorMedico::ESTADOS, true),
            'reembolsar' => $estado === Pago::APROBADO,
            default => false,
        };

        if (! $posible) {
            $this->addError('motivo', 'Ese pago no admite esa acción en su estado actual.');
        }
    }

    // --------------------------------------------------------------------- Lo que la pantalla necesita

    /** ¿Se puede administrar este pago desde acá? Sólo los de citas (los planes van por la pasarela). */
    public function administrable(Pago $pago): bool
    {
        return $pago->esDeCita();
    }

    public function puedeAprobar(Pago $pago): bool
    {
        return $this->administrable($pago) && in_array($pago->estado, PendientesPorMedico::ESTADOS, true);
    }

    public function puedeRechazar(Pago $pago): bool
    {
        return $this->puedeAprobar($pago);
    }

    public function puedeReembolsar(Pago $pago): bool
    {
        return $this->administrable($pago) && $pago->estado === Pago::APROBADO;
    }

    /**
     * "Consultar ahora": fuerza la conciliación contra SyPago. Es la misma puerta que usa el webhook y el
     * sondeo (`Cobros::conciliar`), así que no aprueba nada por su cuenta: reconsulta y aplica.
     */
    public function consultar(int $pagoId, Cobros $cobros): void
    {
        $pago = Pago::findOrFail($pagoId);
        $resultado = $cobros->conciliar($pago, PagoEvento::ORIGEN_MANUAL);

        session()->flash($resultado->ok ? 'message' : 'error', $resultado->mensaje);
    }

    /** Cuántos hay en cada estado, para los chips. */
    public function conteos(): array
    {
        $conteos = Pago::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        $salida = ['todos' => 0];
        foreach (Pago::ESTADOS as $estado) {
            $salida[$estado] = (int) ($conteos[$estado] ?? 0);
            $salida['todos'] += $salida[$estado];
        }

        return $salida;
    }

    public function estadoLegible(string $estado): string
    {
        return match ($estado) {
            Pago::PENDIENTE => 'Pendiente',
            Pago::EN_PROCESO => 'En proceso',
            Pago::APROBADO => 'Aprobado',
            Pago::RECHAZADO => 'Rechazado',
            Pago::CANCELADO => 'Cancelado',
            Pago::EXPIRADO => 'Vencido',
            Pago::REEMBOLSADO => 'Reembolsado',
            default => ucfirst($estado),
        };
    }

    public function estadoTono(string $estado): string
    {
        return match ($estado) {
            Pago::APROBADO => 'success',
            Pago::EN_PROCESO => 'info',
            Pago::PENDIENTE => 'secondary',
            Pago::EXPIRADO => 'warning',
            Pago::REEMBOLSADO => 'primary',
            default => 'danger',
        };
    }

    public function canalLegible(string $canal): string
    {
        return match ($canal) {
            Pago::CANAL_PLAN => 'Plan',
            Pago::CANAL_LINK => 'Link de pago',
            Pago::CANAL_RESERVA_ONLINE => 'Reserva online',
            Pago::CANAL_MOSTRADOR => 'Mostrador',
            default => ucfirst($canal),
        };
    }

    public function render()
    {
        $busqueda = trim((string) $this->search);

        $pagos = Pago::query()
            ->with(['metodoPago'])
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $query->where(function ($where) use ($busqueda) {
                    $where->where('referencia', 'like', "%{$busqueda}%")
                        ->orWhere('internal_id', 'like', "%{$busqueda}%")
                        ->orWhere('referencia_externa', 'like', "%{$busqueda}%")
                        ->orWhere('reg_medico', 'like', "%{$busqueda}%")
                        ->orWhere('concepto', 'like', "%{$busqueda}%");
                });
            })
            ->when($this->estado !== 'todos', fn ($query) => $query->where('estado', $this->estado))
            ->when($this->canal !== 'todos', fn ($query) => $query->where('canal', $this->canal))
            ->orderByDesc('id')
            ->paginate(15);

        // El nombre del médico del tenant. Un `reg_medico` puede tener varias fichas (doctores y centro):
        // se muestra la primera, que es la que el panel usa en el resto de las pantallas.
        $nombres = Medico::query()
            ->whereIn('reg_medico', $pagos->pluck('reg_medico')->filter()->unique()->all())
            ->get()
            ->groupBy('reg_medico')
            ->map(fn ($medicos) => $medicos->first());

        $seleccionado = $this->pagoId
            ? Pago::with(['metodoPago', 'cuentaBancaria', 'comisiones', 'eventos', 'liquidacion'])->find($this->pagoId)
            : null;

        $eventos = $seleccionado
            ? $seleccionado->eventos->map(fn (PagoEvento $evento) => [
                'evento' => $evento,
                'payload' => $this->limpiar($evento->payload ?? []),
            ])
            : collect();

        return view('livewire.admin.pagos', [
            'pagos' => $pagos,
            'nombres' => $nombres,
            'conteos' => $this->conteos(),
            'seleccionado' => $seleccionado,
            'respuestaSegura' => $seleccionado ? $this->limpiar($seleccionado->respuesta ?? []) : [],
            'eventos' => $eventos,
            'aprobadoUsdE2' => (int) Pago::where('estado', Pago::APROBADO)->sum('monto_usd_e2'),
            'aprobadoVesE2' => (int) Pago::where('estado', Pago::APROBADO)->where('moneda', 'VES')->sum('monto_e2'),
            // Lo que está pendiente de cobro, por médico (O-53): es la deuda real con cada consultorio.
            'pendientes' => app(PendientesPorMedico::class)->todos(),
            'totalPendienteUsdE2' => app(PendientesPorMedico::class)->totalUsdE2(),
        ]);
    }

    /**
     * Lo que no se puede mostrar de la respuesta de SyPago: el secreto de la operación, el OTP y cualquier
     * credencial. El resto se muestra tal cual para poder diagnosticar.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function limpiar(array $datos): array
    {
        $secretas = ['otp', 'operation_secret', 'access_token', 'token', 'password', 'authorization', 'secret'];

        array_walk_recursive($datos, function (&$valor, $clave) use ($secretas) {
            if (in_array(strtolower((string) $clave), $secretas, true)) {
                $valor = '••••';
            }
        });

        return $datos;
    }
}
