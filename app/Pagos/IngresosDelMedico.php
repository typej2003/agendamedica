<?php

namespace App\Pagos;

use App\Models\Pago;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * **Los ingresos del médico por citas** (O-53, 2026-11-02): lo que entró por sus consultas, separado por
 * estado y por fecha, para que pueda ver qué está cobrado y qué quedó en proceso.
 *
 * Es de **sólo lectura** y no decide nada del cobro: los pagos los crea `App\Pagos\Cobros` (pasarela) o
 * `App\Actions\Pagos\CobrarEnElMostrador` (mostrador). Acá sólo se leen.
 *
 * Un pago es de un médico cuando **él es el beneficiario** (`beneficiario_reg_medico`) o, en los cobros del
 * mostrador, cuando el pago se creó con su `reg_medico`. Los pagos de plan no entran: son ingreso de la
 * plataforma.
 */
final class IngresosDelMedico
{
    /** Los estados que cuentan como cobrado, en proceso y cerrado sin cobrar. */
    public const PAGADOS = [Pago::APROBADO];

    public const EN_PROCESO = [Pago::PENDIENTE, Pago::EN_PROCESO];

    public const SIN_COBRAR = [Pago::RECHAZADO, Pago::CANCELADO, Pago::EXPIRADO, Pago::REEMBOLSADO];

    /**
     * Los pagos de cita del médico, el último primero.
     *
     * `$estado` acepta uno de los tres grupos (`pagados`, `en_proceso`, `sin_cobrar`) o un estado concreto
     * (`aprobado`, `rechazado`…). Las fechas filtran por el día del pago (o de su creación si todavía no se
     * pagó), en la zona del consultorio.
     *
     * @return Collection<int,Pago>
     */
    public function pagos(
        string $regMedico,
        ?string $estado = null,
        ?CarbonInterface $desde = null,
        ?CarbonInterface $hasta = null,
    ): Collection {
        return Pago::query()
            ->where('pagable_tipo', Pago::PAGABLE_CITA)
            ->where(function ($consulta) use ($regMedico) {
                $consulta->where('beneficiario_reg_medico', $regMedico)
                    ->orWhere('reg_medico', $regMedico);
            })
            ->when($this->estadosDe($estado) !== null, fn ($consulta) => $consulta->whereIn('estado', $this->estadosDe($estado)))
            ->when($desde !== null, fn ($consulta) => $consulta->whereDate('created_at', '>=', $desde->toDateString()))
            ->when($hasta !== null, fn ($consulta) => $consulta->whereDate('created_at', '<=', $hasta->toDateString()))
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /**
     * Los estados del filtro: un grupo o uno concreto. Un valor desconocido no filtra (devuelve `null`).
     *
     * @return array<int,string>|null
     */
    public function estadosDe(?string $estado): ?array
    {
        return match ($estado) {
            'pagados' => self::PAGADOS,
            'en_proceso' => self::EN_PROCESO,
            'sin_cobrar' => self::SIN_COBRAR,
            default => in_array($estado, Pago::ESTADOS, true) ? [$estado] : null,
        };
    }

    /**
     * Los totales del período, ya agrupados como la pantalla los muestra.
     *
     * @return array{pagado_usd_e2:int, en_proceso_usd_e2:int, sin_cobrar_usd_e2:int, pagados:int, en_proceso:int, sin_cobrar:int}
     */
    public function totales(Collection $pagos): array
    {
        $suma = function (array $estados) use ($pagos): int {
            return (int) $pagos
                ->filter(fn (Pago $pago) => in_array($pago->estado, $estados, true))
                ->sum(fn (Pago $pago) => (int) $pago->monto_usd_e2);
        };

        $cuenta = fn (array $estados): int => $pagos->filter(
            fn (Pago $pago) => in_array($pago->estado, $estados, true),
        )->count();

        return [
            'pagado_usd_e2' => $suma(self::PAGADOS),
            'en_proceso_usd_e2' => $suma(self::EN_PROCESO),
            'sin_cobrar_usd_e2' => $suma(self::SIN_COBRAR),
            'pagados' => $cuenta(self::PAGADOS),
            'en_proceso' => $cuenta(self::EN_PROCESO),
            'sin_cobrar' => $cuenta(self::SIN_COBRAR),
        ];
    }
}
