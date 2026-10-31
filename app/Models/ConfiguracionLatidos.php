<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La **política del programa de Latidos**, en una sola fila: si se pueden comprar paquetes (nace apagado),
 * si el programa de referidos está activo, cuántos Latidos gana cada lado y cuánto dura la atribución.
 *
 * No guarda la conversión 100 Latidos = USD 1: esa regla vive en `App\Latidos\Conversion` y es fija para
 * esta versión. Cambiarla es un programa nuevo, no un campo editable.
 */
class ConfiguracionLatidos extends Model
{
    protected $table = 'configuracion_latidos';

    protected $fillable = [
        'compra_activa', 'referidos_activos', 'latidos_referente', 'latidos_referido',
        'vigencia_atribucion_dias', 'tope_mensual_por_referente', 'url_descarga',
    ];

    protected $casts = [
        'compra_activa' => 'boolean',
        'referidos_activos' => 'boolean',
        'latidos_referente' => 'integer',
        'latidos_referido' => 'integer',
        'vigencia_atribucion_dias' => 'integer',
        'tope_mensual_por_referente' => 'integer',
    ];

    /** La fila única; si no existe (instalación nueva), la crea con los valores por defecto. */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create([
                'compra_activa' => false,
                'referidos_activos' => true,
                'latidos_referente' => 2000,
                'latidos_referido' => 2000,
                'vigencia_atribucion_dias' => 90,
                'tope_mensual_por_referente' => 0,
            ]);
    }

    public function compraActiva(): bool
    {
        return (bool) $this->compra_activa;
    }

    public function referidosActivos(): bool
    {
        return (bool) $this->referidos_activos;
    }
}
