<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La **política de pagos de la plataforma**, en una sola fila: la moneda de los libros (USD), la de las
 * liquidaciones (VES), el T+1, el mínimo a liquidar y cuánto vale la tasa que se le muestra al pagador.
 *
 * Las tasas de comisión no viven acá: están en `reglas_de_comision` (con historia) y, si hace falta un
 * override por método, en `metodos_pago.comision_bp`.
 */
class ConfiguracionPago extends Model
{
    public const MONEDA_PLATAFORMA = 'USD';
    public const MONEDA_LIQUIDACION = 'VES';

    /** De dónde sale la tasa VES/USD. */
    public const TASA_MANUAL = 'manual';
    public const TASA_BCV = 'bcv';

    public const TASAS = [
        self::TASA_MANUAL => 'Manual (la carga el administrador)',
        self::TASA_BCV => 'Automática (Banco Central de Venezuela)',
    ];

    protected $table = 'configuracion_pagos';

    protected $fillable = [
        'moneda_plataforma', 'moneda_liquidacion', 'tasa_origen', 'liquidacion_dias', 'liquidacion_minima_usd_e2',
        'vigencia_tasa_minutos', 'aplica_comision_metodo_en_citas_online',
    ];

    protected $casts = [
        'liquidacion_dias' => 'integer',
        'liquidacion_minima_usd_e2' => 'integer',
        'vigencia_tasa_minutos' => 'integer',
        'aplica_comision_metodo_en_citas_online' => 'boolean',
    ];

    /** La fila única; si no existe (instalación nueva), la crea con los valores por defecto. */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create([
                'moneda_plataforma' => self::MONEDA_PLATAFORMA,
                'moneda_liquidacion' => self::MONEDA_LIQUIDACION,
                'tasa_origen' => self::TASA_BCV,
                'liquidacion_dias' => 1,
                'liquidacion_minima_usd_e2' => 0,
                'vigencia_tasa_minutos' => (int) config('pagos.vigencia_pago_minutos', 15),
                'aplica_comision_metodo_en_citas_online' => false,
            ]);
    }

    /** ¿La tasa la sincroniza el comando desde el BCV? */
    public function sincronizaLaTasa(): bool
    {
        return $this->tasa_origen === self::TASA_BCV;
    }
}
