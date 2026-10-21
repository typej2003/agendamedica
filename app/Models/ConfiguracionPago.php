<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La **política de pagos de la plataforma**, en una sola fila: la moneda de los libros (USD), la de las
 * liquidaciones (VES), el T+1, el mínimo a liquidar, la tasa que se le muestra al pagador y si esa tasa se
 * sincroniza sola desde el BCV.
 *
 * Las tasas de comisión no viven acá: están en `reglas_de_comision` (con historia) y, si hace falta un
 * override por método, en `metodos_pago.comision_bp`.
 */
class ConfiguracionPago extends Model
{
    public const MONEDA_PLATAFORMA = 'USD';
    public const MONEDA_LIQUIDACION = 'VES';

    /** Cómo terminó el último intento de sincronización de la tasa. */
    public const SINCRONIZACION_OK = 'ok';
    public const SINCRONIZACION_ERROR = 'error';

    protected $table = 'configuracion_pagos';

    protected $fillable = [
        'moneda_plataforma', 'moneda_liquidacion',
        // La preferencia: ¿el comando `tasas:bcv` actualiza la tasa solo?
        'tasa_automatica',
        // El resultado del último intento (haya cambiado la tasa o no).
        'tasa_sincronizada_el', 'tasa_sincronizacion_estado', 'tasa_sincronizacion_error',
        'liquidacion_dias', 'liquidacion_minima_usd_e2',
        'vigencia_tasa_minutos', 'aplica_comision_metodo_en_citas_online',
    ];

    protected $casts = [
        'tasa_automatica' => 'boolean',
        'tasa_sincronizada_el' => 'datetime',
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
                'tasa_automatica' => true,
                'liquidacion_dias' => 1,
                'liquidacion_minima_usd_e2' => 0,
                'vigencia_tasa_minutos' => (int) config('pagos.vigencia_pago_minutos', 15),
                'aplica_comision_metodo_en_citas_online' => false,
            ]);
    }

    /** ¿La tasa la sincroniza el comando desde el BCV? */
    public function sincronizaLaTasa(): bool
    {
        return (bool) $this->tasa_automatica;
    }

    /**
     * Deja escrito el resultado del último intento de sincronización (lo llama `TasaBcv::intentar`).
     *
     * No guarda la tasa: la tasa vive en `tasas_de_cambio`. Esto es sólo el estado del intento, para que
     * el panel pueda decir "la última vez que se intentó fue … y salió bien/mal".
     */
    public function registrarSincronizacion(bool $ok, ?string $detalle = null): void
    {
        $this->forceFill([
            'tasa_sincronizada_el' => now(),
            'tasa_sincronizacion_estado' => $ok ? self::SINCRONIZACION_OK : self::SINCRONIZACION_ERROR,
            'tasa_sincronizacion_error' => $ok ? null : $detalle,
        ])->save();
    }

    /** «Correcta» o «Con error», para una pantalla. */
    public function estadoSincronizacionLegible(): ?string
    {
        return match ($this->tasa_sincronizacion_estado) {
            self::SINCRONIZACION_OK => 'Correcta',
            self::SINCRONIZACION_ERROR => 'Con error',
            default => null,
        };
    }
}
