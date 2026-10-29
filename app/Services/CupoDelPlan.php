<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Pagos\Centavos;
use App\Support\RestriccionesPlan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * **Los cupos y los precios de un plan para un consultorio** (2026-10-28).
 *
 * El equipo es el `reg_medico`: la ficha dueña (si no es un centro) más los médicos vinculados por
 * `medico_registros`. Las fichas `es_centro` no cuentan como médicos. Las secretarías son cuentas con
 * **solo** el rol `Secretaria`, vinculadas por `medico_registros.user_id`, sin ficha en `medicos`.
 *
 * Reglas:
 *  - Sin servicio (`sin_servicio`) no hay cupo que aplicar al agregar; el centro y lo heredado siguen
 *    funcionando. La impresión sí se bloquea (eso lo resuelve `ServicioService`).
 *  - Una restricción `null` es “sin límite”.
 *  - **El mismo plan se puede renovar aunque el equipo esté por encima**: no se corta el servicio. Un plan
 *    distinto que no cubra al equipo se rechaza; nadie se desvincula automáticamente.
 *  - El dinero se calcula en centavos; nunca con float.
 */
final class CupoDelPlan
{
    public const MEDICO = 'medico';
    public const SECRETARIA = 'secretaria';

    public function __construct(private readonly ServicioService $servicios)
    {
    }

    /** @return array{medicos:int, secretarias:int} */
    public function conteo(string $regMedico): array
    {
        return [
            'medicos' => $this->medicosDelEquipo($regMedico)->count(),
            'secretarias' => $this->secretariasDelEquipo($regMedico)->count(),
        ];
    }

    /**
     * Los médicos del equipo: la ficha dueña (si no es centro) más los vinculados, sin repetir y sin centros.
     *
     * @return Collection<int, Medico>
     */
    public function medicosDelEquipo(string $regMedico): Collection
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            return collect();
        }

        $ids = MedicoRegistro::where('reg_medico', $regMedico)
            ->whereNotNull('medico_id')
            ->pluck('medico_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Medico::with('user')
            ->where('es_centro', false)
            ->where(function ($consulta) use ($regMedico, $ids) {
                $consulta->where('reg_medico', $regMedico);
                if ($ids !== []) {
                    $consulta->orWhereIn('id', $ids);
                }
            })
            ->orderBy('name')
            ->orderBy('lastname')
            ->get()
            ->unique('id')
            ->values();
    }

    /** La ficha dueña del `reg_medico` (un doctor o la fila del centro), para el nombre del equipo. */
    public function duenioDelEquipo(string $regMedico): ?Medico
    {
        $regMedico = trim($regMedico);

        return $regMedico === '' ? null : Medico::where('reg_medico', $regMedico)->orderBy('id')->first();
    }

    /** @return Collection<int, User> */
    public function secretariasDelEquipo(string $regMedico): Collection
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            return collect();
        }

        $ids = MedicoRegistro::where('reg_medico', $regMedico)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return User::whereIn('id', $ids)
            ->whereHas('roles', fn ($consulta) => $consulta->where('name', 'Secretaria'))
            ->whereDoesntHave('roles', fn ($consulta) => $consulta->where('name', '!=', 'Secretaria'))
            ->whereDoesntHave('medico')
            ->orderBy('name')
            ->get();
    }

    public function puedeAgregarMedico(string $regMedico): bool
    {
        $restricciones = $this->restricciones($regMedico);
        if ($restricciones === null) {
            return true; // sin plan: no hay cupo que aplicar (centros y casos heredados)
        }

        $maximo = $restricciones->maxMedicos();

        return $maximo === null || $this->conteo($regMedico)['medicos'] < $maximo;
    }

    public function puedeAgregarSecretaria(string $regMedico): bool
    {
        $restricciones = $this->restricciones($regMedico);
        if ($restricciones === null) {
            return true;
        }

        $maximo = $restricciones->maxSecretarias();

        return $maximo === null || $this->conteo($regMedico)['secretarias'] < $maximo;
    }

    /** El mensaje de “mejora el plan” si no queda cupo de médico; si queda, no hace nada. */
    public function exigirCupoMedico(string $regMedico): void
    {
        if ($this->puedeAgregarMedico($regMedico)) {
            return;
        }

        $maximo = $this->restricciones($regMedico)?->maxMedicos() ?? 0;
        $sugerido = $this->planSugerido($regMedico, self::MEDICO);

        throw ValidationException::withMessages([
            'regNuevo' => $this->mensajeDeCupo(self::MEDICO, $maximo, $sugerido),
        ]);
    }

    /** El mensaje de “mejora el plan” si no queda cupo de secretaría; si queda, no hace nada. */
    public function exigirCupoSecretaria(string $regMedico): void
    {
        if ($this->puedeAgregarSecretaria($regMedico)) {
            return;
        }

        $maximo = $this->restricciones($regMedico)?->maxSecretarias() ?? 0;
        $sugerido = $this->planSugerido($regMedico, self::SECRETARIA);

        throw ValidationException::withMessages([
            'secretaria' => $this->mensajeDeCupo(self::SECRETARIA, $maximo, $sugerido),
        ]);
    }

    /** El plan activo y visible más barato/ordenado que cubriría al equipo actual más uno. */
    public function planSugerido(string $regMedico, string $tipo): ?Plan
    {
        $conteo = $this->conteo($regMedico);
        $necesariosMedicos = $conteo['medicos'] + ($tipo === self::MEDICO ? 1 : 0);
        $necesariasSecretarias = $conteo['secretarias'] + ($tipo === self::SECRETARIA ? 1 : 0);

        return Plan::where('activo', true)
            ->where('visible', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->get()
            ->first(fn (Plan $plan) => ($plan->restricciones->maxMedicos() === null
                    || $plan->restricciones->maxMedicos() >= $necesariosMedicos)
                && ($plan->restricciones->maxSecretarias() === null
                    || $plan->restricciones->maxSecretarias() >= $necesariasSecretarias));
    }

    /** ¿El equipo actual entra en los límites de este plan? */
    public function cabe(Plan $plan, string $regMedico): bool
    {
        $conteo = $this->conteo($regMedico);
        $maxMedicos = $plan->restricciones->maxMedicos();
        $maxSecretarias = $plan->restricciones->maxSecretarias();

        return ($maxMedicos === null || $conteo['medicos'] <= $maxMedicos)
            && ($maxSecretarias === null || $conteo['secretarias'] <= $maxSecretarias);
    }

    /**
     * Exige que el plan cubra al equipo. El **mismo plan** se permite aunque esté por encima (renovación);
     * un plan distinto que no cubra se rechaza.
     *
     * @throws ValidationException
     */
    public function exigirQueCubra(Plan $plan, string $regMedico): void
    {
        $motivo = $this->motivoNoCabe($plan, $regMedico);
        if ($motivo === null) {
            return;
        }

        throw ValidationException::withMessages(['plan' => $motivo]);
    }

    /** El texto de por qué este plan no cubre al equipo, o null si lo cubre o es el mismo plan. */
    public function motivoNoCabe(Plan $plan, string $regMedico): ?string
    {
        $actual = $this->servicios->actual($regMedico);
        if ($actual && $this->esElMismoPlan($actual, $plan)) {
            return null;
        }

        if ($this->cabe($plan, $regMedico)) {
            return null;
        }

        $conteo = $this->conteo($regMedico);
        $maxMedicos = $plan->restricciones->maxMedicos();
        $maxSecretarias = $plan->restricciones->maxSecretarias();

        return 'Tu equipo tiene ' . $conteo['medicos'] . ' médico' . ($conteo['medicos'] === 1 ? '' : 's')
            . ' y ' . $conteo['secretarias'] . ' secretaría' . ($conteo['secretarias'] === 1 ? '' : 's')
            . '; «' . $plan->nombre . '» incluye '
            . ($maxMedicos === null ? 'médicos sin límite' : $maxMedicos . ' médico' . ($maxMedicos === 1 ? '' : 's'))
            . ' y '
            . ($maxSecretarias === null ? 'secretarías sin límite' : $maxSecretarias . ' secretaría' . ($maxSecretarias === 1 ? '' : 's'))
            . '. Quita a alguien del equipo o elige un plan que los cubra.';
    }

    /** El precio del plan para este equipo, en centavos de USD. */
    public function precioUsdE2(Plan $plan, array $conteo): int
    {
        if (! $plan->cobraPorAsiento()) {
            return Centavos::deDecimal((string) $plan->precio_usd);
        }

        $precioAsiento = Centavos::deDecimal((string) $plan->caracteristicas->precioAsientoUsd());
        $meses = $plan->frecuencia === Plan::ANUAL ? 12 : 1;

        return $precioAsiento * $plan->asientosQueSeCobran(
            (int) ($conteo['medicos'] ?? 0),
            (int) ($conteo['secretarias'] ?? 0),
        ) * $meses;
    }

    /** El precio mínimo del plan (para “desde” en la portada y el panel). */
    public function precioDesdeUsdE2(Plan $plan): int
    {
        return $this->precioUsdE2($plan, ['medicos' => 0, 'secretarias' => 0]);
    }

    public function asientos(Plan $plan, array $conteo): int
    {
        return $plan->asientosQueSeCobran(
            (int) ($conteo['medicos'] ?? 0),
            (int) ($conteo['secretarias'] ?? 0),
        );
    }

    /** “4 asientos × USD 12,00 · 1 secretaría incluida”, o null si el plan es de precio fijo. */
    public function desglose(Plan $plan, array $conteo): ?string
    {
        if (! $plan->cobraPorAsiento()) {
            return null;
        }

        $asientos = $this->asientos($plan, $conteo);
        $incluidas = $plan->caracteristicas->secretariasIncluidas();
        $texto = $asientos . ' asiento' . ($asientos === 1 ? '' : 's') . ' × '
            . Centavos::formatear(Centavos::deDecimal((string) $plan->caracteristicas->precioAsientoUsd()), 'USD');

        if ($incluidas > 0) {
            $texto .= ' · ' . $incluidas . ' secretaría' . ($incluidas === 1 ? '' : 's')
                . ' incluida' . ($incluidas === 1 ? '' : 's');
        }

        return $texto;
    }

    /** @return RestriccionesPlan|null null = sin plan */
    private function restricciones(string $regMedico): ?RestriccionesPlan
    {
        $estado = $this->servicios->estado($regMedico);
        if (($estado['estado'] ?? '') === ServicioService::SIN_SERVICIO) {
            return null;
        }

        return RestriccionesPlan::desde($estado['restricciones'] ?? []);
    }

    private function esElMismoPlan(RegMedicoServicio $actual, Plan $plan): bool
    {
        if ($actual->plan_id !== null) {
            return (int) $actual->plan_id === (int) $plan->id;
        }

        return trim((string) $actual->plan_nombre) === trim((string) $plan->nombre);
    }

    private function mensajeDeCupo(string $tipo, int $maximo, ?Plan $sugerido): string
    {
        $palabra = $tipo === self::MEDICO ? 'médico' : 'secretaría';
        $otro = $tipo === self::MEDICO ? 'otro' : 'otra';
        $texto = 'Tu plan incluye ' . $maximo . ' ' . $palabra . ($maximo === 1 ? '' : 's')
            . '. Para agregar ' . $otro . ', mejora el plan';

        if ($sugerido) {
            $texto .= ' (por ejemplo, «' . $sugerido->nombre . '»)';
        }

        return $texto . '.';
    }
}
