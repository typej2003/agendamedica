<?php

namespace App\Models;

use App\Casts\CredencialesCifradasCast;
use App\Pagos\CatalogoDePasarelas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un **método de pago** operable: la fila que se configura en el panel y la que resuelve el motor.
 *
 * `propietario = plataforma` son los métodos de Doctorisimo (cobrar planes y, a futuro, prestárselos a
 * un médico que no tiene canal propio). `propietario = medico` son los del consultorio, con su propio
 * SyPago y su cuenta.
 *
 * Las credenciales van cifradas (`CredencialesCifradasCast`) y el `webhook_token` se genera solo: es la
 * parte secreta de la URL que SyPago llama para avisar el resultado.
 */
class MetodoPago extends Model
{
    public const PLATAFORMA = 'plataforma';
    public const MEDICO = 'medico';

    public const PRODUCCION = 'produccion';
    public const PRUEBAS = 'pruebas';

    public const PLANES = 'planes';
    public const COBROS = 'cobros';
    public const AMBOS = 'ambos';

    protected $table = 'metodos_pago';

    protected $fillable = [
        'propietario', 'reg_medico', 'pasarela', 'variante', 'nombre', 'activo', 'orden', 'entorno',
        'credenciales', 'datos', 'cuenta_bancaria_id', 'comision_bp', 'disponible_para', 'webhook_token',
        'verificado_el', 'ultimo_error',
    ];

    protected $casts = [
        'credenciales' => CredencialesCifradasCast::class,
        'datos' => 'array',
        'activo' => 'boolean',
        'comision_bp' => 'integer',
        'verificado_el' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MetodoPago $metodo) {
            if (empty($metodo->webhook_token)) {
                $metodo->webhook_token = Str::random(64);
            }
        });
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'metodo_pago_id');
    }

    public function scopeDeLaPlataforma(Builder $query): Builder
    {
        return $query->where('propietario', self::PLATAFORMA);
    }

    public function scopeDelMedico(Builder $query, string $regMedico): Builder
    {
        return $query->where('propietario', self::MEDICO)->where('reg_medico', $regMedico);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** Los que se pueden ofrecer para `planes`, `cobros` o los dos. */
    public function scopeParaUso(Builder $query, string $uso): Builder
    {
        return $query->whereIn('disponible_para', [$uso, self::AMBOS]);
    }

    public function esDeLaPlataforma(): bool
    {
        return $this->propietario === self::PLATAFORMA;
    }

    public function etiqueta(): string
    {
        return $this->nombre !== '' ? $this->nombre : CatalogoDePasarelas::etiqueta($this->pasarela, $this->variante);
    }

    /** ¿Tiene credenciales y cuenta como para intentar un cobro? */
    public function estaConfigurado(): bool
    {
        if ($this->pasarela === CatalogoDePasarelas::MANUAL) {
            return true;
        }

        $credenciales = $this->credenciales ?? [];

        return trim((string) ($credenciales['access_token'] ?? '')) !== ''
            && ($this->cuentaBancaria?->estaCompleta() ?? false);
    }

    /**
     * Las credenciales para mostrar en el panel: el token enmascarado. Nunca se manda el valor real a la
     * vista.
     *
     * @return array{host:string, access_token:string, tiene_token:bool}
     */
    public function credencialesSeguras(): array
    {
        $credenciales = $this->credenciales ?? [];
        $token = (string) ($credenciales['access_token'] ?? '');

        return [
            'host' => (string) ($credenciales['host'] ?? config('pagos.sypago.host')),
            'access_token' => $token === '' ? '' : '••••' . substr($token, -4),
            'tiene_token' => $token !== '',
        ];
    }
}
