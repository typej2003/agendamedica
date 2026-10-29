<?php

namespace App\Models;

use App\Casts\CaracteristicasPlanCast;
use App\Casts\RestriccionesPlanCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan del catálogo. Comercialmente importan la frecuencia, el monto (fijo o por asiento), los límites de
 * equipo (`restricciones`) y las capacidades (`caracteristicas`); el monto tachado, la descripción, el orden
 * y la visibilidad son visuales. Ver `create_planes_y_reg_medico_servicio_tables` y la migración de
 * `caracteristicas`.
 */
class Plan extends Model
{
    public const MENSUAL = 'mensual';
    public const ANUAL = 'anual';

    protected $table = 'planes';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion', 'frecuencia', 'precio_usd', 'precio_anual_usd', 'precio_tachado_usd',
        'restricciones', 'caracteristicas', 'es_default', 'visible', 'activo', 'orden',
    ];

    protected $casts = [
        'precio_usd' => 'decimal:2',
        'precio_anual_usd' => 'decimal:2',
        'precio_tachado_usd' => 'decimal:2',
        'restricciones' => RestriccionesPlanCast::class,
        'caracteristicas' => CaracteristicasPlanCast::class,
        'es_default' => 'boolean',
        'visible' => 'boolean',
        'activo' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Solo un plan es el default: el que recibe un médico recién registrado.
        static::saved(function (Plan $plan) {
            if ($plan->es_default) {
                static::where('id', '!=', $plan->id)->where('es_default', true)->update(['es_default' => false]);
            }
        });
    }

    public static function porDefecto(): ?self
    {
        return static::where('es_default', true)->where('activo', true)->first();
    }

    public static function porCodigo(string $codigo): ?self
    {
        return static::where('codigo', $codigo)->first();
    }

    /** Meses de servicio que da una compra: 1 si es mensual, 12 si es anual. */
    public function meses(?string $frecuencia = null): int
    {
        $frecuencia = $frecuencia ?? $this->frecuenciaPorDefecto();

        return $frecuencia === self::ANUAL ? 12 : 1;
    }

    /**
     * ¿El plan se puede pagar por mes? (un plan por asiento siempre tiene la modalidad mensual)
     *
     * Una fila **anual vieja** guardaba el precio del año en `precio_usd`; no tiene modalidad mensual.
     */
    public function ofreceMensual(): bool
    {
        if ($this->frecuencia === self::ANUAL && $this->precio_anual_usd === null) {
            return false;
        }

        return $this->cobraPorAsiento() || (float) $this->precio_usd > 0;
    }

    /** ¿El plan se puede pagar por año? */
    public function ofreceAnual(): bool
    {
        if ($this->cobraPorAsiento()) {
            return $this->caracteristicas->mesesCobradosAnual() > 0;
        }
        if ($this->precio_anual_usd !== null) {
            return (float) $this->precio_anual_usd > 0;
        }

        // Compatibilidad: una fila anual vieja (incluida la cortesía de PowerBuilder, con precio 0).
        return $this->frecuencia === self::ANUAL;
    }

    /** La frecuencia que se elige por defecto al abrir la compra. */
    public function frecuenciaPorDefecto(): string
    {
        if ($this->frecuencia === self::ANUAL && $this->ofreceAnual()) {
            return self::ANUAL;
        }
        if ($this->ofreceMensual()) {
            return self::MENSUAL;
        }

        return $this->ofreceAnual() ? self::ANUAL : self::MENSUAL;
    }

    /** Lo que se ahorra frente al monto tachado; 0 si no hay tachado o no es mayor que el precio. */
    public function ahorroUsd(): float
    {
        if ($this->precio_tachado_usd === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->precio_tachado_usd - (float) $this->precio_usd, 2));
    }

    /** El ahorro del anual frente a doce meses sueltos (sólo para un plan de precio fijo). */
    public function ahorroAnualUsd(): float
    {
        if (! $this->ofreceAnual() || $this->cobraPorAsiento() || $this->precio_anual_usd === null) {
            return 0.0;
        }

        return max(0.0, round(12 * (float) $this->precio_usd - (float) $this->precio_anual_usd, 2));
    }

    /** ¿Se cobra por asiento en vez de un monto fijo? */
    public function cobraPorAsiento(): bool
    {
        return $this->caracteristicas->cobraPorAsiento();
    }

    /**
     * Los asientos que se cobrarían con este equipo: médicos + secretarías que excedan las incluidas,
     * con el piso de `minimo_asientos`. Siempre al menos 1.
     */
    public function asientosQueSeCobran(int $medicos, int $secretarias): int
    {
        $incluidas = $this->caracteristicas->secretariasIncluidas();

        return max(
            1,
            $this->caracteristicas->minimoAsientos(),
            max(0, $medicos) + max(0, $secretarias - $incluidas),
        );
    }
}
