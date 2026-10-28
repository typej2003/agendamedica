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
        'codigo', 'nombre', 'descripcion', 'frecuencia', 'precio_usd', 'precio_tachado_usd',
        'restricciones', 'caracteristicas', 'es_default', 'visible', 'activo', 'orden',
    ];

    protected $casts = [
        'precio_usd' => 'decimal:2',
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
    public function meses(): int
    {
        return $this->frecuencia === self::ANUAL ? 12 : 1;
    }

    /** Lo que se ahorra frente al monto tachado; 0 si no hay tachado o no es mayor que el precio. */
    public function ahorroUsd(): float
    {
        if ($this->precio_tachado_usd === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->precio_tachado_usd - (float) $this->precio_usd, 2));
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
