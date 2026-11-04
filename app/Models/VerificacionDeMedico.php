<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **Una verificación de la ficha médica** (2026-11-03): el carnet profesional, el correo o el teléfono,
 * cada una por separado. Pedido de Alexander: el carnet deja de ser obligatorio para usar la app, pero su
 * estado —y el de los otros dos— queda guardado en el perfil para poder validarlo después.
 *
 * Vive en `verificaciones_medico`, tabla nueva de AppDDR (no viaja por el sync). El estado es uno de
 * [ESTADOS]: `sin_verificar` (nunca se revisó), `pendiente` (hay algo que revisar, como la foto del
 * carnet), `verificado` y `rechazado`. Una ficha sin fila para un tipo se lee como `sin_verificar`
 * (`VerificacionesDelMedico::estado()`), así los médicos del escritorio no necesitan una fila para existir.
 *
 * Sólo el `carnet` puede cortar el acceso, y sólo cuando el administrador enciende
 * `configuracion_verificacion_medicos.exigir_carnet` (ver `App\Verificacion\AccesoDelMedico`). Un carnet
 * `rechazado` corta siempre: es la marca de que el administrador encontró un problema.
 */
class VerificacionDeMedico extends Model
{
    public const CARNET = 'carnet';

    public const CORREO = 'correo';

    public const TELEFONO = 'telefono';

    /** Los tres tipos, en el orden en que se muestran. */
    public const TIPOS = [self::CARNET, self::CORREO, self::TELEFONO];

    public const SIN_VERIFICAR = 'sin_verificar';

    public const PENDIENTE = 'pendiente';

    public const VERIFICADO = 'verificado';

    public const RECHAZADO = 'rechazado';

    public const ESTADOS = [self::SIN_VERIFICAR, self::PENDIENTE, self::VERIFICADO, self::RECHAZADO];

    protected $table = 'verificaciones_medico';

    protected $fillable = [
        'medico_id', 'tipo', 'estado', 'verificado_el', 'verificado_por', 'nota',
    ];

    protected $casts = [
        'verificado_el' => 'datetime',
    ];

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    /** El administrador que la dio por verificada (o la rechazó); nulo si la marcó el propio sistema. */
    public function verificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificado_por');
    }

    public function estaVerificada(): bool
    {
        return $this->estado === self::VERIFICADO;
    }

    /** El rótulo que se muestra en el panel. */
    public static function nombre(string $tipo): string
    {
        return match ($tipo) {
            self::CARNET => 'Carnet',
            self::CORREO => 'Correo',
            self::TELEFONO => 'Teléfono',
            default => $tipo,
        };
    }

    /** El tono de la pastilla de estado (`<x-ui.estado>`). */
    public static function tono(string $estado): string
    {
        return match ($estado) {
            self::VERIFICADO => 'exito',
            self::PENDIENTE => 'aviso',
            self::RECHAZADO => 'peligro',
            default => 'neutro',
        };
    }
}
