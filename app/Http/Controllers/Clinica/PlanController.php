<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Pagos\Vistas\PagoVista;
use App\Clinica\Pagos\Vistas\PlanVista;
use App\Http\Controllers\Controller;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\TasaDeCambio;
use App\Pagos\CatalogoDePasarelas;
use App\Pagos\Cobros;
use App\Services\ServicioService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * **Cobrar el plan del médico con SyPago** (MVP, 2026-10-21).
 *
 * El médico ve su servicio y el catálogo, elige un plan y un método de la plataforma, carga sus datos de
 * pagador, recibe el OTP y lo confirma. El pago se crea en `pendiente` con la tasa congelada; la
 * aprobación y la renovación del servicio las hace `App\Pagos\Cobros` (también desde el webhook y el
 * sondeo), no este controlador.
 *
 * El pago se busca **por el `reg_medico` del contexto**: un médico no puede pagar ni mirar el pago de otro
 * adivinando el id.
 */
class PlanController extends Controller
{
    public function index(Request $request, ServicioService $servicios)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        $planes = Plan::where('activo', true)
            ->where('visible', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $metodos = MetodoPago::deLaPlataforma()
            ->activos()
            ->paraUso(MetodoPago::PLANES)
            ->orderBy('orden')
            ->orderBy('id')
            ->get()
            // El MVP cobra con SyPago; un método manual no tiene cómo debitar el plan.
            ->filter(fn (MetodoPago $metodo) => $metodo->pasarela === CatalogoDePasarelas::SYPAGO
                && $metodo->estaConfigurado())
            ->values();

        return view('clinica.plan.index', [
            'vm' => new PlanVista($regMedico, $servicios->estado($regMedico), $planes, $metodos, TasaDeCambio::actual()),
        ]);
    }

    /** Crea la intención de pago y manda al checkout. Todavía no cobra. */
    public function comprar(Request $request, Cobros $cobros)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        $datos = $request->validate([
            'plan_id' => ['required', 'integer'],
            'metodo_pago_id' => ['required', 'integer'],
        ], [], ['plan_id' => 'plan', 'metodo_pago_id' => 'método de pago']);

        $plan = Plan::where('activo', true)->where('visible', true)->find($datos['plan_id']);
        if (! $plan) {
            return back()->with('error', 'Ese plan ya no está disponible.');
        }

        $metodo = MetodoPago::deLaPlataforma()
            ->activos()
            ->paraUso(MetodoPago::PLANES)
            ->find($datos['metodo_pago_id']);

        if (! $metodo || $metodo->pasarela !== CatalogoDePasarelas::SYPAGO || ! $metodo->estaConfigurado()) {
            return back()->with('error', 'Ese método de pago no está disponible.');
        }

        try {
            $pago = $cobros->crearParaPlan($regMedico, $plan, $metodo, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('clinica.plan.pagar', $pago);
    }

    /** El checkout: resumen y el paso actual del pago. */
    public function pagar(Request $request, Pago $pago)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        // Un pendiente vencido se muestra como vencido, sin esperar al comando.
        if ($pago->estado === Pago::PENDIENTE && $pago->expira_el && $pago->expira_el->isPast()) {
            $pago->update(['estado' => Pago::EXPIRADO]);
        }

        return view('clinica.plan.pagar', [
            'vm' => new PagoVista($pago, $pago->metodoPago, TasaDeCambio::actual()),
        ]);
    }

    /** Pide el OTP con los datos del pagador. */
    public function iniciar(Request $request, Pago $pago, Cobros $cobros)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        if (! $pago->estaPendiente()) {
            return redirect()->route('clinica.plan.pagar', $pago)->with('error', 'Este pago ya no está pendiente.');
        }

        $metodo = $pago->metodoPago;
        if (! $metodo) {
            return redirect()->route('clinica.plan.pagar', $pago)->with('error', 'El pago no tiene un método asociado.');
        }

        $reglas = [
            'documento_tipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'documento_numero' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:120'],
            'banco_codigo' => ['required', 'digits:4'],
        ];

        if ($metodo->variante === 'pago_movil') {
            $reglas['telefono'] = ['required', 'string', 'max:20'];
        } else {
            $reglas['cuenta'] = ['required', 'string', 'min:10', 'max:30'];
        }

        $datos = $request->validate($reglas, [
            'documento_numero.required' => 'Falta tu documento.',
            'nombre.required' => 'Falta tu nombre.',
            'banco_codigo.digits' => 'El código del banco son 4 dígitos (por ejemplo 0172).',
            'cuenta.required' => 'Falta el número de cuenta desde donde pagás.',
            'telefono.required' => 'Falta el teléfono del pago móvil.',
        ], [
            'documento_tipo' => 'tipo de documento', 'documento_numero' => 'documento',
            'nombre' => 'nombre', 'banco_codigo' => 'banco', 'cuenta' => 'cuenta', 'telefono' => 'teléfono',
        ]);

        $resultado = $cobros->iniciar($pago, $datos);

        return redirect()
            ->route('clinica.plan.pagar', $pago)
            ->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /** Confirma el cobro con el OTP. */
    public function confirmar(Request $request, Pago $pago, Cobros $cobros)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        $datos = $request->validate([
            'otp' => ['required', 'string', 'min:4', 'max:10'],
        ], [
            'otp.required' => 'Escribí el código que te llegó al teléfono.',
        ], ['otp' => 'código']);

        $resultado = $cobros->confirmar($pago, $datos['otp']);

        return redirect()
            ->route('clinica.plan.pagar', $pago)
            ->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /** "Ya pagué, consultar": fuerza la conciliación sin esperar al sondeo. */
    public function consultar(Request $request, Pago $pago, Cobros $cobros)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        $resultado = $cobros->conciliar($pago);

        return redirect()
            ->route('clinica.plan.pagar', $pago)
            ->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    private function regMedicoDelContexto(Request $request): string
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';

        abort_if($regMedico === '', 403, 'Elegí un consultorio para ver tu plan.');

        return $regMedico;
    }

    private function pagoDelContexto(Request $request, Pago $pago): Pago
    {
        abort_unless((string) $pago->reg_medico === $this->regMedicoDelContexto($request), 404);

        return $pago;
    }
}
