<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * **La política de verificación de médicos**, en una sola fila (2026-11-03). Hoy tiene un solo
 * interruptor: si el registro exige el carnet verificado.
 *
 * Apagado (el valor con el que nace, y el que Alexander pidió para «por ahora») el médico entra solo al
 * confirmar su correo y su carnet queda `sin_verificar` o `pendiente`; encendido, el registro vuelve a
 * esperar la aprobación del administrador y el médico sin carnet verificado no puede usar la app
 * (`App\Verificacion\AccesoDelMedico`).
 *
 * Sigue el patrón de `ConfiguracionLatidos`/`ConfiguracionPago`: una fila, `actual()` la crea si falta.
 */
class ConfiguracionDeVerificacion extends Model
{
    protected $table = 'configuracion_verificacion_medicos';

    protected $fillable = ['exigir_carnet'];

    protected $casts = ['exigir_carnet' => 'boolean'];

    /** La fila única; si no existe (instalación nueva), la crea con el valor por defecto (apagado). */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create(['exigir_carnet' => false]);
    }

    public function exigeCarnet(): bool
    {
        return (bool) $this->exigir_carnet;
    }
}
