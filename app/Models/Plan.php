<?php

namespace App\Models;

use App\Casts\RestriccionesPlanCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan del catálogo. Comercialmente solo importan la frecuencia de cobro y el monto; el monto tachado, la
 * descripción, el orden y la visibilidad son visuales. Ver la migración `create_planes_y_reg_medico_servicio_tables`.
 */
class Plan extends Model
{
    public const MENSUAL = 'mensual';
    public const ANUAL = 'anual';

    protected $table = 'planes';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion', 'frecuencia', 'precio_usd', 'precio_tachado_usd',
        'restricciones', 'es_default', 'visible', 'activo', 'orden',
    ];

    protected $casts = [
        'precio_usd' => 'decimal:2',
        'precio_tachado_usd' => 'decimal:2',
        'restricciones' => RestriccionesPlanCast::class,
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
}
