<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\Pago;
use App\Models\PagoEvento;
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
