<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Pagos\Vistas\PagoVista;
use App\Clinica\Pagos\Vistas\PlanElegidoVista;
use App\Clinica\Pagos\Vistas\PlanVista;
use App\Http\Controllers\Controller;
use App\Latidos\Conversion;
use App\Latidos\Latidos;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\TasaDeCambio;
use App\Pagos\CatalogoDePasarelas;
use App\Pagos\Cobros;
use App\Pagos\RefrescarPagoDePlan;
use App\Pagos\UltimoPagoDelDoctor;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * **Cobrar el plan del médico con SyPago** (MVP, 2026-10-21).
 *
 * Tres pantallas:
 *  1. `/clinica/plan` — el servicio actual y el catálogo. Sólo se elige el plan.
 *  2. `/clinica/plan/{plan}` — el plan elegido, el método de pago y el monto en la moneda del método.
 *  3. `/clinica/plan/pago/{pago}` — el checkout de SyPago: datos del pagador, OTP y resultado.
 *
 * El pago se crea en `pendiente` al confirmar la pantalla 2, con la tasa congelada. La aprobación y la
 * renovación las hace `App\Pagos\Cobros` (también desde el webhook y el sondeo), no este controlador.
 *
 * El pago se busca **por el `reg_medico` del contexto**: un médico no puede pagar ni mirar el pago de otro
 * adivinando el id.
 */
class PlanController extends Controller
{
    public function index(Request $request, ServicioService $servicios, CupoDelPlan $cupos, Latidos $latidos)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        $planes = Plan::where('activo', true)
            ->where('visible', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $frecuencia = (string) $request->query('frecuencia', Plan::ANUAL);
        if (! in_array($frecuencia, [Plan::MENSUAL, Plan::ANUAL], true)) {
            $frecuencia = Plan::ANUAL;
        }

        $conteo = $cupos->conteo($regMedico);
        $precios = [];
        $preciosAnuales = [];
        $ahorrosAnuales = [];
        $detalles = [];
        $detallesAnuales = [];
        $motivos = [];

        foreach ($planes as $plan) {
            $precios[$plan->id] = $cupos->precioUsdE2($plan, $conteo, Plan::MENSUAL);
            $preciosAnuales[$plan->id] = $cupos->precioUsdE2($plan, $conteo, Plan::ANUAL);
            $ahorrosAnuales[$plan->id] = $plan->ofreceMensual() ? $cupos->ahorroAnualUsdE2($plan, $conteo) : 0;
            $detalles[$plan->id] = $cupos->desglose($plan, $conteo, Plan::MENSUAL);
            $detallesAnuales[$plan->id] = $cupos->desglose($plan, $conteo, Plan::ANUAL);
            $motivos[$plan->id] = $cupos->motivoNoCabe($plan, $regMedico);
        }

        return view('clinica.plan.index', [
            'vm' => new PlanVista(
                $regMedico,
                $servicios->estado($regMedico),
                $planes,
                $conteo,
                $precios,
                $detalles,
                $motivos,
                $preciosAnuales,
                $ahorrosAnuales,
                $detallesAnuales,
                $frecuencia,
                $latidos->disponiblesParaUsar($regMedico),
            ),
        ]);
    }

    /** La pantalla del plan elegido: método de pago y monto en su moneda. */
    public function elegir(Request $request, Plan $plan, CupoDelPlan $cupos, Latidos $latidos)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        // Sólo se puede pagar un plan del catálogo vigente.
        $plan = Plan::where('activo', true)->where('visible', true)->findOrFail($plan->id);

        $motivo = $cupos->motivoNoCabe($plan, $regMedico);
        if ($motivo !== null) {
            return redirect()->route('clinica.plan')->with('error', $motivo);
        }

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

        $conteo = $cupos->conteo($regMedico);

        // Al elegir un plan, el médico arranca en anual si el plan lo ofrece; puede cambiar a mensual.
        $frecuencia = (string) $request->query('frecuencia', '');
        if (! in_array($frecuencia, [Plan::MENSUAL, Plan::ANUAL], true)) {
            $frecuencia = $plan->ofreceAnual() ? Plan::ANUAL : Plan::MENSUAL;
        }
        if ($frecuencia === Plan::ANUAL && ! $plan->ofreceAnual()) {
            $frecuencia = Plan::MENSUAL;
        }
        if ($frecuencia === Plan::MENSUAL && ! $plan->ofreceMensual()) {
            $frecuencia = Plan::ANUAL;
        }

        $precioMensualE2 = $cupos->precioUsdE2($plan, $conteo, Plan::MENSUAL);
        $precioAnualE2 = $cupos->precioUsdE2($plan, $conteo, Plan::ANUAL);
        $precioE2 = $cupos->precioUsdE2($plan, $conteo, $frecuencia);

        // Los Latidos se aplican primero; la pasarela sólo cobra la diferencia.
        $disponibles = $latidos->disponiblesParaUsar($regMedico);
        $necesarios = Conversion::desdeUsdE2($precioE2);
        $latidosUsar = min($disponibles, $necesarios);
        $descuentoUsdE2 = Conversion::aUsdE2($latidosUsar);
        $aPagarUsdE2 = max(0, $precioE2 - $descuentoUsdE2);

        return view('clinica.plan.elegir', [
            'vm' => new PlanElegidoVista(
                $plan,
                $metodos,
                TasaDeCambio::actual(),
                $precioE2,
                $cupos->desglose($plan, $conteo, $frecuencia),
                $frecuencia,
                $precioMensualE2,
                $precioAnualE2,
                $cupos->ahorroAnualUsdE2($plan, $conteo),
                $disponibles,
                $latidosUsar,
                $descuentoUsdE2,
                $aPagarUsdE2,
            ),
        ]);
    }

    /** Crea la intención de pago y manda al checkout. Todavía no cobra. */
    public function comprar(Request $request, Cobros $cobros)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        $datos = $request->validate([
            'plan_id' => ['required', 'integer'],
            // Es nullable: un plan cubierto al 100 % con Latidos no necesita método.
            'metodo_pago_id' => ['nullable', 'integer'],
            'frecuencia' => ['nullable', Rule::in([Plan::MENSUAL, Plan::ANUAL])],
        ], [
            'frecuencia.in' => 'Elige pagar por mes o por año.',
        ], ['plan_id' => 'plan', 'metodo_pago_id' => 'método de pago', 'frecuencia' => 'frecuencia']);

        $plan = Plan::where('activo', true)->where('visible', true)->find($datos['plan_id']);
        if (! $plan) {
            return back()->with('error', 'Ese plan ya no está disponible.');
        }

        $metodo = null;
        if (! empty($datos['metodo_pago_id'])) {
            $metodo = MetodoPago::deLaPlataforma()
                ->activos()
                ->paraUso(MetodoPago::PLANES)
                ->find($datos['metodo_pago_id']);

            if (! $metodo || $metodo->pasarela !== CatalogoDePasarelas::SYPAGO || ! $metodo->estaConfigurado()) {
                return back()->with('error', 'Ese método de pago no está disponible.');
            }
        }

        $frecuencia = (string) ($datos['frecuencia'] ?? ($plan->ofreceAnual() ? Plan::ANUAL : Plan::MENSUAL));

        try {
            $pago = $cobros->crearParaPlan($regMedico, $plan, $metodo, $request->user()->id, $frecuencia);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('clinica.plan.pagar', $pago);
    }

    /** El checkout: resumen y el paso actual del pago. */
    public function pagar(Request $request, Pago $pago)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        return view('clinica.plan.pagar', [
            'vm' => new PagoVista(
                $pago,
                $pago->metodoPago,
                app(UltimoPagoDelDoctor::class)->datosParaPagar($request->user()->id),
            ),
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
            'cuenta' => ['required', 'string', 'max:30'],
        ];

        $datos = $request->validate($reglas, [
            'documento_numero.required' => 'Falta tu documento.',
            'nombre.required' => 'Falta tu nombre.',
            'banco_codigo.digits' => 'El código del banco son 4 dígitos (por ejemplo 0172).',
            'cuenta.required' => 'Falta la cuenta o el teléfono desde donde pagas.',
        ], [
            'documento_tipo' => 'tipo de documento', 'documento_numero' => 'documento',
            'nombre' => 'nombre', 'banco_codigo' => 'banco', 'cuenta' => 'cuenta o teléfono',
        ]);

        $numero = preg_replace('/\D/', '', (string) $datos['cuenta']);

        // No se pregunta si es cuenta o pago móvil: el formato del número lo dice. Un teléfono
        // venezolano son 11 dígitos que empiezan con 04; una cuenta son 20 que empiezan con el banco.
        if (preg_match('/^04\d{9}$/', $numero)) {
            $datos['tipo_cuenta'] = 'pago_movil';
            $datos['telefono'] = $numero;
        } elseif (strlen($numero) === 20 && str_starts_with($numero, (string) $datos['banco_codigo'])) {
            $datos['tipo_cuenta'] = 'cuenta';
        } else {
            throw ValidationException::withMessages([
                'cuenta' => 'Escribe una cuenta de 20 dígitos que empiece con el código del banco, '
                    . 'o el teléfono del pago móvil (11 dígitos, empieza con 04).',
            ]);
        }

        $datos['cuenta'] = $numero;

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
            'otp.required' => 'Escribe el código que te llegó al teléfono.',
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

    /**
     * Renueva el pago cuando se acaba el tiempo (lo llama el JS del checkout).
     *
     * `aplicar=false` es la consulta automática: si la tasa no cambió, renueva la vigencia y deja seguir;
     * si cambió, no toca el pago y devuelve `cambio` para que el médico vea el botón «Reintentar».
     * `aplicar=true` es el «Reintentar»: actualiza el pago con la tasa nueva.
     */
    public function refrescar(Request $request, Pago $pago, RefrescarPagoDePlan $refrescar)
    {
        $pago = $this->pagoDelContexto($request, $pago);

        try {
            $resultado = $refrescar->ejecutar($pago, $request->boolean('aplicar'));
        } catch (RuntimeException $e) {
            return response()->json(['estado' => 'error', 'mensaje' => $e->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    private function regMedicoDelContexto(Request $request): string
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';

        abort_if($regMedico === '', 403, 'Elige un consultorio para ver tu plan.');

        return $regMedico;
    }

    private function pagoDelContexto(Request $request, Pago $pago): Pago
    {
        abort_unless((string) $pago->reg_medico === $this->regMedicoDelContexto($request), 404);

        return $pago;
    }
}
