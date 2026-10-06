<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\RegMedicoServicio;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * Servicio contratado por `reg_medico`: cuál es el vigente, en qué estado está y si deja sincronizar.
 *
 * Estados (por la fecha `vence_el`, que cuenta completa — se sirve hasta el final de ese día):
 *  - `vigente`: no ha vencido.
 *  - `gracia`:  venció hace [config servicios.dias_gracia] días o menos. Se sincroniza, pero ya figura vencido.
 *  - `vencido`: pasó la gracia. **No se sincroniza**: el API responde 402 `servicio_vencido`.
 *  - `sin_servicio`: el `reg_medico` no tiene ninguna fila. Tampoco sincroniza; no debería pasar, porque
 *    cada médico recibe su prueba al crearse y quien sincroniza desde el escritorio, su año de cortesía.
 *
 * Lo único que se bloquea es la sincronización. Lo demás (ver lo que ya hay en el teléfono o el escritorio,
 * iniciar sesión, el panel) sigue igual.
 */
class ServicioService
{
    public const VIGENTE = 'vigente';
    public const GRACIA = 'gracia';
    public const VENCIDO = 'vencido';
    public const SIN_SERVICIO = 'sin_servicio';

    /** El servicio activo de mayor vencimiento; null si no tiene ninguno. */
    public function actual(string $regMedico): ?RegMedicoServicio
    {
        return RegMedicoServicio::where('reg_medico', $regMedico)
            ->where('estado', RegMedicoServicio::ACTIVO)
            ->orderByDesc('vence_el')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{estado:string, permite_sync:bool, reg_medico:string, plan:?string, plan_codigo:?string, origen:?string, inicia_el:?string, vence_el:?string, dias_restantes:?int, gracia_hasta:?string, restricciones:array}
     */
    public function estado(string $regMedico, ?Carbon $hoy = null): array
    {
        $servicio = $this->actual($regMedico);
        if (! $servicio) {
            return [
                'estado' => self::SIN_SERVICIO, 'permite_sync' => false, 'reg_medico' => $regMedico,
                'plan' => null, 'plan_codigo' => null, 'origen' => null, 'inicia_el' => null, 'vence_el' => null,
                'dias_restantes' => null, 'gracia_hasta' => null, 'restricciones' => [],
            ];
        }

        $hoy = ($hoy ?? Carbon::today())->copy()->startOfDay();
        $vence = $servicio->vence_el->copy()->startOfDay();
        $dias = (int) $hoy->diffInDays($vence, false); // negativo si ya venció
        $graciaHasta = $vence->copy()->addDays(config('servicios.dias_gracia'));

        $estado = $dias >= 0 ? self::VIGENTE : ($hoy->lte($graciaHasta) ? self::GRACIA : self::VENCIDO);

        return [
            'estado' => $estado,
            'permite_sync' => $estado !== self::VENCIDO,
            'reg_medico' => $regMedico,
            'plan' => $servicio->plan_nombre,
            'plan_codigo' => $servicio->plan?->codigo,
            'origen' => $servicio->origen,
            'inicia_el' => $servicio->inicia_el->toDateString(),
            'vence_el' => $vence->toDateString(),
            'dias_restantes' => $dias,
            'gracia_hasta' => $graciaHasta->toDateString(),
            'restricciones' => $servicio->restricciones->toArray(),
        ];
    }

    /**
     * El mejor estado entre varios `reg_medico` (un médico puede estar en más de un consultorio): el primero
     * que deja sincronizar y, si ninguno, el que venció más tarde.
     *
     * @param  list<string>  $regsMedico
     */
    public function mejorEstado(array $regsMedico, ?Carbon $hoy = null): array
    {
        $mejor = null;
        foreach ($regsMedico as $reg) {
            $e = $this->estado($reg, $hoy);
            if ($e['permite_sync']) {
                return $e;
            }
            if ($mejor === null || (string) $e['vence_el'] > (string) $mejor['vence_el']) {
                $mejor = $e;
            }
        }

        return $mejor ?? $this->estado('', $hoy);
    }

    public function permiteSync(string $regMedico): bool
    {
        return $this->estado($regMedico)['permite_sync'];
    }

    /**
     * La respuesta de error a devolver si el servicio no deja sincronizar; null si todo bien.
     * 402 con `error`/`code` `servicio_vencido` (o `sin_servicio`): `error` lo lee PowerBuilder por el
     * puente (`sync.exe --kv-out`), `code` lo lee el app, igual que sus otros errores de cuenta.
     */
    public function bloqueo(array $estado): ?JsonResponse
    {
        if ($estado['permite_sync']) {
            return null;
        }

        $codigo = $estado['estado'] === self::SIN_SERVICIO ? 'sin_servicio' : 'servicio_vencido';
        $mensaje = $estado['estado'] === self::SIN_SERVICIO
            ? 'Este médico no tiene un servicio contratado: no se puede sincronizar.'
            : 'El servicio de este médico venció el ' . Carbon::parse($estado['vence_el'])->format('d/m/Y')
                . ': la sincronización está suspendida hasta renovarlo.';

        return response()->json([
            'ok' => false,
            'error' => $codigo,
            'code' => $codigo,
            'mensaje' => $mensaje,
            'message' => $mensaje,
            'vence_el' => $estado['vence_el'],
            'estado_servicio' => $estado['estado'],
        ], 402);
    }

    /**
     * Un médico recién registrado: el plan por defecto, gratis, por [config servicios.prueba_meses] mes(es) —
     * aunque el default sea de pago, el primer mes no se cobra. Solo si ese `reg_medico` no tiene nada todavía.
     */
    public function otorgarPrueba(string $regMedico): ?RegMedicoServicio
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '' || RegMedicoServicio::where('reg_medico', $regMedico)->exists()) {
            return null;
        }
        $plan = Plan::porDefecto();
        if (! $plan) {
            return null;
        }

        return $this->otorgar(
            $regMedico, $plan, RegMedicoServicio::ORIGEN_REGISTRO,
            meses: (int) config('servicios.prueba_meses'), monto: 0, nota: 'Prueba gratuita al registrarse',
        );
    }

    /**
     * Quien sincroniza desde el escritorio PowerBuilder recibe un año gratis (una sola vez por `reg_medico`),
     * cuente o no con otro servicio: arranca hoy, no se suma al que ya tenga.
     */
    public function otorgarPowerBuilder(string $regMedico): ?RegMedicoServicio
    {
        $regMedico = trim($regMedico);
        if ($regMedico === ''
            || RegMedicoServicio::where('reg_medico', $regMedico)->where('origen', RegMedicoServicio::ORIGEN_POWERBUILDER)->exists()) {
            return null;
        }
        $plan = Plan::porCodigo((string) config('servicios.plan_powerbuilder'));
        if (! $plan) {
            return null;
        }

        return $this->otorgar($regMedico, $plan, RegMedicoServicio::ORIGEN_POWERBUILDER, monto: 0, nota: 'Un año de cortesía por usar el escritorio');
    }

    /**
     * Renovar o contratar un plan. Si el servicio actual todavía no vence, el nuevo período empieza donde
     * termina ese (no se pierde nada de lo ya pagado); si no, empieza hoy.
     *
     * @param  float|null  $monto  lo cobrado; por defecto, el precio del plan
     */
    public function renovar(string $regMedico, Plan $plan, ?float $monto = null, ?string $nota = null, string $origen = RegMedicoServicio::ORIGEN_COMPRA): RegMedicoServicio
    {
        $actual = $this->actual($regMedico);
        $desde = $actual && $actual->vence_el->gte(Carbon::today()) ? $actual->vence_el->copy() : null;

        return $this->otorgar($regMedico, $plan, $origen, monto: $monto ?? (float) $plan->precio_usd, desde: $desde, nota: $nota);
    }

    /**
     * Crea una fila de servicio con las restricciones del plan copiadas.
     *
     * @param  int|null  $meses  por defecto, los del plan (1 mensual, 12 anual)
     */
    public function otorgar(
        string $regMedico,
        Plan $plan,
        string $origen,
        ?int $meses = null,
        float $monto = 0,
        ?Carbon $desde = null,
        ?string $nota = null
    ): RegMedicoServicio {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw new DomainException('Falta el reg_medico.');
        }
        $meses = $meses ?? $plan->meses();
        if ($meses < 1) {
            throw new DomainException('El servicio tiene que durar al menos un mes.');
        }

        $inicia = ($desde ?? Carbon::today())->copy()->startOfDay();

        return RegMedicoServicio::create([
            'reg_medico' => $regMedico,
            'plan_id' => $plan->id,
            'plan_nombre' => $plan->nombre,
            'origen' => $origen,
            'inicia_el' => $inicia,
            'vence_el' => $inicia->copy()->addMonthsNoOverflow($meses),
            'monto_usd' => $monto,
            'restricciones' => $plan->restricciones,
            'estado' => RegMedicoServicio::ACTIVO,
            'nota' => $nota,
        ]);
    }
}
