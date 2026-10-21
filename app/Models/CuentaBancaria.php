<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La **cuenta bancaria** de la plataforma o de un médico: la que recibe el dinero (la del comercio en
 * SyPago) y la que se usa para pagarle al médico en una liquidación.
 *
 * Se guarda aparte del método de pago porque el médico puede cambiar de banco sin reconfigurar SyPago, y
 * porque la liquidación necesita la cuenta de destino aunque el cobro haya entrado por otro lado.
 */
class CuentaBancaria extends Model
{
    public const PLATAFORMA = 'plataforma';
    public const MEDICO = 'medico';

    public const CUENTA = 'cuenta';
    public const PAGO_MOVIL = 'pago_movil';

    public const DOCUMENTOS = ['V', 'E', 'J', 'G', 'P'];

    protected $table = 'cuentas_bancarias';

    protected $fillable = [
        'propietario', 'reg_medico', 'moneda', 'tipo', 'banco_codigo', 'numero', 'telefono',
        'titular', 'documento_tipo', 'documento_numero', 'predeterminada', 'activa', 'verificada_el',
    ];

    protected $casts = [
        'predeterminada' => 'boolean',
        'activa' => 'boolean',
        'verificada_el' => 'datetime',
    ];

    public function metodosPago(): HasMany
    {
        return $this->hasMany(MetodoPago::class, 'cuenta_bancaria_id');
    }

    /** ¿Tiene lo mínimo para que SyPago pueda usarla? */
    public function estaCompleta(): bool
    {
        if ($this->tipo === self::PAGO_MOVIL) {
            return trim((string) $this->telefono) !== '';
        }

        return trim((string) $this->numero) !== '';
    }

    /** «Bancamiga · cuenta 0123…», para una pantalla. */
    public function descripcion(): string
    {
        $cuenta = $this->tipo === self::PAGO_MOVIL ? $this->telefono : $this->numero;
        $cuenta = trim((string) $cuenta);

        return 'Banco ' . $this->banco_codigo
            . ($cuenta === '' ? '' : ' · ' . ($this->tipo === self::PAGO_MOVIL ? 'pago móvil ' : 'cuenta ') . $cuenta);
    }
}
