<?php

namespace App\Models;

use App\Casts\CredencialesCifradasCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La **cuenta bancaria** de la plataforma o de un médico: la que recibe el dinero (la del comercio en
 * SyPago), la del pago móvil o la que se usa para pagarle al médico en una liquidación.
 *
 * Se guarda aparte del método de pago porque el médico puede cambiar de banco sin reconfigurar SyPago, y
 * porque la liquidación necesita la cuenta de destino aunque el cobro haya entrado por otro lado.
 *
 * **Pago móvil:** la misma fila guarda el teléfono (pago móvil) y el número de cuenta (transferencia).
 * `conciliacion` dice cómo se confirma lo que entra: `manual` (lo confirma el consultorio) o `automatica`
 * (se consulta la API del banco). Las credenciales de esa API van cifradas y nunca se muestran.
 */
class CuentaBancaria extends Model
{
    public const PLATAFORMA = 'plataforma';
    public const MEDICO = 'medico';

    public const CUENTA = 'cuenta';
    public const PAGO_MOVIL = 'pago_movil';

    public const CONCILIACION_MANUAL = 'manual';
    public const CONCILIACION_AUTOMATICA = 'automatica';

    public const DOCUMENTOS = ['V', 'E', 'J', 'G', 'P'];

    protected $table = 'cuentas_bancarias';

    protected $fillable = [
        'propietario', 'reg_medico', 'moneda', 'tipo', 'banco_codigo', 'numero', 'telefono',
        'titular', 'documento_tipo', 'documento_numero', 'predeterminada', 'activa', 'verificada_el',
        'conciliacion', 'credenciales', 'conciliacion_verificada_el', 'conciliacion_error',
    ];

    protected $casts = [
        'predeterminada' => 'boolean',
        'activa' => 'boolean',
        'verificada_el' => 'datetime',
        'credenciales' => CredencialesCifradasCast::class,
        'conciliacion_verificada_el' => 'datetime',
    ];

    public function metodosPago(): HasMany
    {
        return $this->hasMany(MetodoPago::class, 'cuenta_bancaria_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'cuenta_bancaria_id');
    }

    /** ¿Tiene lo mínimo para que SyPago pueda usarla? */
    public function estaCompleta(): bool
    {
        if ($this->tipo === self::PAGO_MOVIL) {
            return trim((string) $this->telefono) !== '';
        }

        return trim((string) $this->numero) !== '';
    }

    /** ¿Se puede recibir un pago móvil en esta cuenta? */
    public function aceptaPagoMovil(): bool
    {
        return $this->numeroDePagoMovil() !== '' && trim((string) $this->documento_numero) !== '';
    }

    /** ¿Se puede recibir una transferencia en esta cuenta? */
    public function aceptaTransferencia(): bool
    {
        return $this->numeroDeCuenta() !== '';
    }

    public function numeroDePagoMovil(): string
    {
        return (string) preg_replace('/\D/', '', (string) $this->telefono);
    }

    public function numeroDeCuenta(): string
    {
        return (string) preg_replace('/\D/', '', (string) $this->numero);
    }

    /** «V-12345678», como lo pide la consulta bancaria. */
    public function documentoDelTitular(): string
    {
        $tipo = strtoupper(trim((string) $this->documento_tipo));
        $numero = (string) preg_replace('/\D/', '', (string) $this->documento_numero);

        return $tipo . $numero;
    }

    public function conciliacionAutomatica(): bool
    {
        return $this->conciliacion === self::CONCILIACION_AUTOMATICA;
    }

    /** El banco de la cuenta, según el catálogo de `config/pagos.php`. */
    public function banco(): ?array
    {
        $codigo = trim((string) $this->banco_codigo);

        return $codigo !== '' ? (config('pagos.pago_movil.bancos.' . $codigo) ?: null) : null;
    }

    public function bancoNombre(): string
    {
        $codigo = trim((string) $this->banco_codigo);
        $catalogo = (array) config('pagos.pago_movil.catalogo', []);

        return $this->banco()['nombre']
            ?? $catalogo[$codigo]
            ?? ($codigo !== '' ? 'Banco ' . $codigo : '');
    }

    /** `bancaribe`, `bancamiga` o `null` si el banco no tiene API de consulta. */
    public function driverDeConciliacion(): ?string
    {
        return $this->banco()['driver'] ?? null;
    }

    /** ¿El banco de esta cuenta permite confirmar automáticamente? */
    public function soportaConciliacionAutomatica(): bool
    {
        return $this->driverDeConciliacion() !== null;
    }

    /** ¿Tiene credenciales cargadas para consultar la API del banco? */
    public function tieneCredencialesDeConciliacion(): bool
    {
        $credenciales = $this->credenciales ?? [];

        return trim((string) ($credenciales['consumer_key'] ?? '')) !== ''
            && trim((string) ($credenciales['consumer_secret'] ?? '')) !== '';
    }

    /** ¿Se puede confirmar sola esta cuenta? */
    public function conciliaSola(): bool
    {
        return $this->conciliacionAutomatica()
            && $this->soportaConciliacionAutomatica()
            && $this->tieneCredencialesDeConciliacion();
    }

    /**
     * Las credenciales para el panel: la clave y el secreto enmascarados. Nunca se manda el valor real a
     * la vista.
     *
     * @return array{consumer_key:string, consumer_secret:string, token_url:string, consulta_url:string, tiene_secret:bool}
     */
    public function credencialesSeguras(): array
    {
        $credenciales = $this->credenciales ?? [];
        $secreto = (string) ($credenciales['consumer_secret'] ?? '');

        return [
            'consumer_key' => (string) ($credenciales['consumer_key'] ?? ''),
            'consumer_secret' => $secreto === '' ? '' : '••••' . substr($secreto, -4),
            'token_url' => (string) ($credenciales['token_url'] ?? ''),
            'consulta_url' => (string) ($credenciales['consulta_url'] ?? ''),
            'tiene_secret' => $secreto !== '',
        ];
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
