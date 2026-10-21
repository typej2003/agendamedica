<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una **regla de comisión** con historia: `metodo_pago` (por prestar el método de la plataforma) o
 * `cita_online` (por la cita que trae la plataforma). El porcentaje va en puntos base (`10000` = 100%).
 *
 * La regla que se aplica es la más específica y vigente: la que menciona al médico o al método gana
 * sobre la global; entre iguales, la más nueva. El pago guarda una copia (`pago_comisiones`), así que
 * cambiar una regla después no reescribe lo ya cobrado.
 */
class ReglaDeComision extends Model
{
    public const TIPO_METODO = 'metodo_pago';
    public const TIPO_CITA_ONLINE = 'cita_online';

    public const TIPOS = [
        self::TIPO_METODO => 'Comisión por método de pago',
        self::TIPO_CITA_ONLINE => 'Comisión por cita online',
    ];

    public const GLOBAL = 'global';
    public const MEDICO = 'medico';
    public const METODO = 'metodo';
    public const PLAN = 'plan';

    protected $table = 'reglas_de_comision';

    protected $fillable = [
        'tipo', 'ambito', 'reg_medico', 'metodo_pago_id', 'plan_id', 'porcentaje_bp',
        'vigente_desde', 'activo', 'creado_por',
    ];

    protected $casts = [
        'porcentaje_bp' => 'integer',
        'vigente_desde' => 'datetime',
        'activo' => 'boolean',
    ];

    /**
     * La regla que corresponde aplicar, o `null` si no hay ninguna (comisión 0).
     *
     * Se filtra por lo que menciona la regla: una regla con `reg_medico` sólo aplica a ese médico; una con
     * `metodo_pago_id`, sólo a ese método; la global aplica siempre.
     */
    public static function vigente(
        string $tipo,
        ?string $regMedico = null,
        ?int $metodoPagoId = null,
        ?int $planId = null
    ): ?self {
        return static::query()
            ->where('tipo', $tipo)
            ->where('activo', true)
            ->where('vigente_desde', '<=', now())
            ->where(function (Builder $q) use ($regMedico, $metodoPagoId, $planId) {
                $q->where(function (Builder $global) {
                    $global->where('ambito', self::GLOBAL);
                });

                if ($regMedico !== null) {
                    $q->orWhere(function (Builder $medico) use ($regMedico) {
                        $medico->where('ambito', self::MEDICO)->where('reg_medico', $regMedico);
                    });
                }
                if ($metodoPagoId !== null) {
                    $q->orWhere(function (Builder $metodo) use ($metodoPagoId) {
                        $metodo->where('ambito', self::METODO)->where('metodo_pago_id', $metodoPagoId);
                    });
                }
                if ($planId !== null) {
                    $q->orWhere(function (Builder $plan) use ($planId) {
                        $plan->where('ambito', self::PLAN)->where('plan_id', $planId);
                    });
                }
            })
            ->orderByRaw('(reg_medico IS NOT NULL) DESC')
            ->orderByRaw('(metodo_pago_id IS NOT NULL) DESC')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }
}
