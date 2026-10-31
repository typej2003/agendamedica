<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\RegMedicoServicio;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Servicio contratado por `reg_medico`: cuál es el vigente, en qué estado está y si deja sincronizar o imprimir.
 *
 * Estados (por la fecha `vence_el`, que cuenta completa — se sirve hasta el final de ese día):
 *  - `vigente`: no ha vencido.
 *  - `gracia`:  venció hace [config servicios.dias_gracia] días o menos. Se sincroniza, pero ya figura vencido.
 *  - `vencido`: pasó la gracia. **No se sincroniza** (402 `servicio_vencido`) **ni se imprime**.
 *  - `sin_servicio`: el `reg_medico` no tiene ninguna fila. Tampoco sincroniza ni imprime; no debería pasar,
 *    porque cada médico recibe su prueba al crearse y quien sincroniza desde el escritorio, su año de cortesía.
 *
 * La impresión además depende de la característica `impresion_reportes` del plan contratado. Lo demás (ver lo
 * que ya hay en el teléfono o el escritorio, iniciar sesión, el panel) sigue igual.
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
     * @return array{estado:string, permite_sync:bool, permite_imprimir:bool, reg_medico:string, plan:?string, plan_id:?int, plan_codigo:?string, origen:?string, inicia_el:?string, vence_el:?string, dias_restantes:?int, gracia_hasta:?string, restricciones:array, caracteristicas:array}
     */
    public function estado(string $regMedico, ?Carbon $hoy = null): array
    {
        $servicio = $this->actual($regMedico);
        if (! $servicio) {
            return [
                'estado' => self::SIN_SERVICIO, 'permite_sync' => false, 'permite_imprimir' => false,
                'reg_medico' => $regMedico, 'plan' => null, 'plan_id' => null, 'plan_codigo' => null,
                'origen' => null, 'inicia_el' => null, 'vence_el' => null, 'dias_restantes' => null,
                'gracia_hasta' => null, 'restricciones' => [], 'caracteristicas' => [],
            ];
        }

        $c = $this->clasificar($servicio->vence_el, $hoy);

        return [
            'estado' => $c['estado'],
            'permite_sync' => $c['estado'] !== self::VENCIDO,
            // La gracia imprime igual que sincroniza; el corte es al pasar a vencido o si el plan no la incluye.
            'permite_imprimir' => $c['estado'] !== self::VENCIDO && $servicio->caracteristicas->impresionReportes(),
            'reg_medico' => $regMedico,
            'plan' => $servicio->plan_nombre,
            'plan_id' => $servicio->plan_id,
            'plan_codigo' => $servicio->plan?->codigo,
            'origen' => $servicio->origen,
            'inicia_el' => $servicio->inicia_el->toDateString(),
            'vence_el' => $servicio->vence_el->toDateString(),
            'dias_restantes' => $c['dias'],
            'gracia_hasta' => $c['gracia_hasta'],
            'restricciones' => $servicio->restricciones->toArray(),
            'caracteristicas' => $servicio->caracteristicas->toArray(),
        ];
    }

    /** ¿El plan vigente (o en gracia) de este consultorio deja imprimir reportes y PDF? */
    public function permiteImprimir(string $regMedico, ?Carbon $hoy = null): bool
    {
        return (bool) ($this->estado($regMedico, $hoy)['permite_imprimir'] ?? false);
    }

    /** El texto para el aviso y la redirección; null si el plan sí deja imprimir. */
    public function motivoDeNoImprimir(string $regMedico, ?array $estado = null): ?string
    {
        $estado = $estado ?? $this->estado($regMedico);

        if (($estado['permite_imprimir'] ?? false) === true) {
            return null;
        }

        if (($estado['estado'] ?? '') === self::SIN_SERVICIO) {
            return 'Este consultorio no tiene un plan activo: no se pueden imprimir reportes ni generar PDF.';
        }

        if (($estado['estado'] ?? '') === self::VENCIDO) {
            $vence = $estado['vence_el'] ?? null;
            $texto = $vence
                ? 'El plan de este consultorio venció el ' . Carbon::parse($vence)->format(\App\Support\Regionalizacion::formatoActual())
                : 'El plan de este consultorio venció';

            return $texto . ': no se pueden imprimir reportes ni generar PDF hasta renovarlo.';
        }

        return 'Tu plan no incluye la impresión de reportes ni PDF. Mejora el plan para imprimir.';
    }

    /**
     * Estado según la fecha de vencimiento (la única regla, la usan la API y el panel).
     *
     * @return array{estado:string, dias:int, gracia_hasta:string} `dias` es negativo si ya venció
     */
    public function clasificar(Carbon $venceEl, ?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? \App\Clinica\FechaDelConsultorio::hoyComoFecha())->copy()->startOfDay();
        $vence = $venceEl->copy()->startOfDay();
        $dias = (int) $hoy->diffInDays($vence, false);
        $graciaHasta = $vence->copy()->addDays(config('servicios.dias_gracia'));

        return [
            'estado' => $dias >= 0 ? self::VIGENTE : ($hoy->lte($graciaHasta) ? self::GRACIA : self::VENCIDO),
            'dias' => $dias,
            'gracia_hasta' => $graciaHasta->toDateString(),
        ];
    }

    /**
     * El vencimiento vigente de cada `reg_medico` con servicio, en una sola consulta (para listados).
     *
     * @return array<string, Carbon> reg_medico => `vence_el`
     */
    public function vencimientos(): array
    {
        return RegMedicoServicio::where('estado', RegMedicoServicio::ACTIVO)
            ->selectRaw('reg_medico, max(vence_el) as vence_el')
            ->groupBy('reg_medico')
            ->pluck('vence_el', 'reg_medico')
            ->map(fn ($fecha) => Carbon::parse($fecha))
            ->all();
    }

    /** Anula una fila del historial (p. ej. una renovación cargada por error): deja de contar, no se borra. */
    public function cancelar(RegMedicoServicio $servicio, ?string $nota = null): void
    {
        $servicio->forceFill([
            'estado' => RegMedicoServicio::CANCELADO,
            'nota' => trim(($servicio->nota ? $servicio->nota . ' · ' : '') . 'Cancelado' . ($nota ? ': ' . $nota : '')),
        ])->save();
    }

    /**
     * Quita el servicio a un `reg_medico` (una incidencia, un fraude): cancela **todos** sus períodos activos —no
     * solo el vigente, o al cancelar uno aparecería el siguiente— y se queda sin servicio, o sea sin sincronizar,
     * desde ya. Las filas quedan en el historial con el motivo, quién lo hizo y cuándo; no se vuelve a regalar una
     * prueba (`otorgarPrueba` mira cualquier fila, cancelada o no). Se arregla asignándole un plan nuevo.
     *
     * @return int cuántos períodos se cancelaron
     */
    public function cancelarActivos(string $regMedico, string $motivo, ?string $por = null): int
    {
        $nota = $this->notaDeAuditoria($motivo, $por);

        return DB::transaction(function () use ($regMedico, $nota) {
            $activos = RegMedicoServicio::where('reg_medico', $regMedico)->where('estado', RegMedicoServicio::ACTIVO)->get();
            $activos->each(fn (RegMedicoServicio $s) => $this->cancelar($s, $nota));

            return $activos->count();
        });
    }

    /**
     * Cambia el plan de un `reg_medico` **desde hoy** y sin conservar el tiempo que le quedaba: cancela todo lo
     * activo y crea el período nuevo en la misma operación (si algo falla, no queda sin servicio a medias).
     *
     * @param  float  $monto  lo cobrado; 0 = cortesía
     * @param  int|null  $meses  por defecto, los del plan
     */
    public function reemplazar(
        string $regMedico,
        Plan $plan,
        float $monto,
        string $motivo,
        ?int $meses = null,
        ?string $por = null
    ): RegMedicoServicio {
        $nota = $this->notaDeAuditoria($motivo, $por);

        return DB::transaction(function () use ($regMedico, $plan, $monto, $meses, $nota) {
            RegMedicoServicio::where('reg_medico', $regMedico)->where('estado', RegMedicoServicio::ACTIVO)->get()
                ->each(fn (RegMedicoServicio $s) => $this->cancelar($s, 'reemplazado. ' . $nota));

            return $this->otorgar(
                $regMedico, $plan, $monto > 0 ? RegMedicoServicio::ORIGEN_COMPRA : RegMedicoServicio::ORIGEN_MANUAL,
                meses: $meses, monto: $monto, nota: 'Reemplaza el plan anterior. ' . $nota,
            );
        });
    }

    /** "Motivo (por Fulano, 06/10/2026)": lo que queda escrito en el historial de quien cambió el servicio. */
    private function notaDeAuditoria(string $motivo, ?string $por): string
    {
        return trim($motivo) . ' (' . ($por ? "por {$por}, " : '') . \App\Clinica\FechaDelConsultorio::hoyComoFecha()->format('d/m/Y') . ')';
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
     * En un plan **por asiento** el llamador tiene que pasar el monto ya calculado con `CupoDelPlan`
     * (`precioUsdE2`): acá no se adivina el equipo. La web, el panel y el comando lo hacen.
     *
     * @param  float|null  $monto  lo cobrado; por defecto, el precio fijo del plan
     * @param  int|null  $meses  por defecto, los del plan (así se puede dar de más: "paga 10, disfruta 12")
     */
    public function renovar(
        string $regMedico,
        Plan $plan,
        ?float $monto = null,
        ?string $nota = null,
        string $origen = RegMedicoServicio::ORIGEN_COMPRA,
        ?int $meses = null
    ): RegMedicoServicio {
        return $this->otorgar(
            $regMedico, $plan, $origen,
            meses: $meses, monto: $monto ?? (float) $plan->precio_usd, desde: $this->inicioDeRenovacion($regMedico), nota: $nota,
        );
    }

    /**
     * **Renovar justo el día del vencimiento** (la renovación automática con Latidos, 2026-10-31).
     *
     * El día `vence_el` todavía es del período viejo: el servicio se sirve hasta el final de ese día. Si se
     * usara `renovar()`, el período nuevo arrancaría hoy y regalaría un día. Acá arranca **mañana**.
     *
     * Si el comando llegó tarde y el servicio ya venció (gracia), el período nuevo arranca **hoy**, como
     * cualquier renovación de un servicio vencido.
     */
    public function renovarAlVencer(
        string $regMedico,
        Plan $plan,
        ?float $monto = null,
        ?string $nota = null,
        string $origen = RegMedicoServicio::ORIGEN_COMPRA,
        ?int $meses = null
    ): RegMedicoServicio {
        $actual = $this->actual($regMedico);
        $hoy = \App\Clinica\FechaDelConsultorio::hoyComoFecha();
        $desde = null;

        if ($actual) {
            if ($actual->vence_el->equalTo($hoy)) {
                $desde = $actual->vence_el->copy()->addDay();
            } elseif ($actual->vence_el->gt($hoy)) {
                $desde = $actual->vence_el->copy();
            }
        }

        return $this->otorgar(
            $regMedico, $plan, $origen,
            meses: $meses, monto: $monto ?? (float) $plan->precio_usd, desde: $desde, nota: $nota,
        );
    }

    /**
     * Desde cuándo empezaría un período nuevo: donde termina el servicio actual si todavía no venció (no se
     * pierde nada de lo ya pagado); null = hoy.
     */
    public function inicioDeRenovacion(string $regMedico): ?Carbon
    {
        $actual = $this->actual($regMedico);

        return $actual && $actual->vence_el->gte(\App\Clinica\FechaDelConsultorio::hoyComoFecha()) ? $actual->vence_el->copy() : null;
    }

    /**
     * Crea una fila de servicio con las restricciones y las características del plan copiadas.
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

        $inicia = ($desde ?? \App\Clinica\FechaDelConsultorio::hoyComoFecha())->copy()->startOfDay();

        return RegMedicoServicio::create([
            'reg_medico' => $regMedico,
            'plan_id' => $plan->id,
            'plan_nombre' => $plan->nombre,
            'origen' => $origen,
            'inicia_el' => $inicia,
            'vence_el' => $inicia->copy()->addMonthsNoOverflow($meses),
            'monto_usd' => $monto,
            'restricciones' => $plan->restricciones,
            'caracteristicas' => $plan->caracteristicas,
            'estado' => RegMedicoServicio::ACTIVO,
            'nota' => $nota,
        ]);
    }
}
