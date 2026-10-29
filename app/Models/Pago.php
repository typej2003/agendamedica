<?php

namespace App\Models;

use App\Pagos\Centavos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * **Un pago**: la intención y la transacción, con el desglose de comisiones congelado.
 *
 * Dos preguntas separadas, a propósito:
 *  - `beneficiario` — a quién le corresponde la plata (la plataforma en un plan; el médico en una consulta).
 *  - `recaudador` — quién la recibió de verdad (el médico con su método; la plataforma cuando presta el
 *    suyo o cuando la cita es online).
 *
 * Los montos están en centavos (`_e2`). `monto_e2` es en `moneda` (lo que se cobró); `monto_usd_e2` es el
 * equivalente en la moneda de la plataforma; `tasa` es la VES/USD del momento. Las comisiones se calculan
 * en USD y el recibo en VES se arma con los equivalentes, para que sume exacto en las dos monedas.
 */
class Pago extends Model
{
    public const BENEFICIARIO_PLATAFORMA = 'plataforma';
    public const BENEFICIARIO_MEDICO = 'medico';

    public const RECAUDADOR_PLATAFORMA = 'plataforma';
    public const RECAUDADOR_MEDICO = 'medico';

    public const CANAL_PLAN = 'plan';
    public const CANAL_LINK = 'link';
    public const CANAL_RESERVA_ONLINE = 'reserva_online';
    public const CANAL_MOSTRADOR = 'mostrador';

    public const PAGABLE_PLAN = 'plan';
    public const PAGABLE_CITA = 'cita';
    public const PAGABLE_PREFACTURA = 'prefactura';

    public const PENDIENTE = 'pendiente';
    public const EN_PROCESO = 'en_proceso';
    public const APROBADO = 'aprobado';
    public const RECHAZADO = 'rechazado';
    public const CANCELADO = 'cancelado';
    public const EXPIRADO = 'expirado';
    public const REEMBOLSADO = 'reembolsado';

    public const ESTADOS = [
        self::PENDIENTE, self::EN_PROCESO, self::APROBADO, self::RECHAZADO,
        self::CANCELADO, self::EXPIRADO, self::REEMBOLSADO,
    ];

    public const LIQUIDACION_NO_APLICA = 'no_aplica';
    public const LIQUIDACION_PENDIENTE = 'pendiente';
    public const LIQUIDACION_LIQUIDADO = 'liquidado';

    protected $table = 'pagos';

    protected $fillable = [
        'referencia', 'internal_id', 'token_publico', 'beneficiario_tipo', 'beneficiario_reg_medico', 'reg_medico', 'recaudador',
        'canal', 'pagable_tipo', 'pagable_id', 'concepto', 'metodo_pago_id', 'cuenta_bancaria_id', 'meses',
        'moneda', 'monto_e2', 'monto_usd_e2', 'tasa',
        'comision_metodo_usd_e2', 'comision_cita_online_usd_e2', 'comision_total_usd_e2', 'neto_usd_e2',
        'comision_total_ves_e2', 'neto_ves_e2',
        'estado', 'referencia_externa', 'operation_secret', 'datos_pagador', 'respuesta', 'otp_enviado_el', 'otp_intentos',
        'pagado_el', 'expira_el', 'disponible_el', 'estado_liquidacion', 'liquidacion_id', 'creado_por',
    ];

    protected $casts = [
        'monto_e2' => 'integer',
        'monto_usd_e2' => 'integer',
        'meses' => 'integer',
        'tasa' => 'decimal:6',
        'comision_metodo_usd_e2' => 'integer',
        'comision_cita_online_usd_e2' => 'integer',
        'comision_total_usd_e2' => 'integer',
        'neto_usd_e2' => 'integer',
        'comision_total_ves_e2' => 'integer',
        'neto_ves_e2' => 'integer',
        'datos_pagador' => 'array',
        'respuesta' => 'array',
        'otp_intentos' => 'integer',
        'otp_enviado_el' => 'datetime',
        'pagado_el' => 'datetime',
        'expira_el' => 'datetime',
        'disponible_el' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Pago $pago) {
            if (empty($pago->referencia)) {
                $pago->referencia = (string) Str::uuid();
            }

            // SyPago limita `internal_id` a 35 caracteres: un UUID con guiones tiene 36. Se manda el UUID
            // sin guiones (32), que es único igual.
            if (empty($pago->internal_id)) {
                $pago->internal_id = substr(str_replace('-', '', (string) Str::uuid()), 0, 35);
            }
        });
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function comisiones(): HasMany
    {
        return $this->hasMany(PagoComision::class, 'pago_id')->orderBy('secuencia');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(PagoEvento::class, 'pago_id')->orderBy('id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }

    public function detalleDeLiquidacion(): HasMany
    {
        return $this->hasMany(LiquidacionPago::class, 'pago_id');
    }

    public function estaAprobado(): bool
    {
        return $this->estado === self::APROBADO;
    }

    public function estaEnProceso(): bool
    {
        return in_array($this->estado, [self::EN_PROCESO], true);
    }

    public function estaPendiente(): bool
    {
        return in_array($this->estado, [self::PENDIENTE, self::EN_PROCESO], true);
    }

    public function esDePlan(): bool
    {
        return $this->pagable_tipo === self::PAGABLE_PLAN;
    }

    public function esDeCita(): bool
    {
        return $this->pagable_tipo === self::PAGABLE_CITA;
    }

    /** El plan que se está pagando, si es un pago de plan. */
    public function plan(): ?Plan
    {
        return $this->esDePlan() && $this->pagable_id ? Plan::find($this->pagable_id) : null;
    }

    /** La cita que se está pagando, si es un pago de consulta. */
    public function cita(): ?Cola
    {
        return $this->esDeCita() && $this->pagable_id ? Cola::find($this->pagable_id) : null;
    }

    public function montoUsdTexto(): string
    {
        return Centavos::formatear($this->monto_usd_e2, 'USD');
    }

    public function montoTexto(): string
    {
        return Centavos::formatear($this->monto_e2, $this->moneda);
    }

    public function netoUsdTexto(): string
    {
        return Centavos::formatear($this->neto_usd_e2, 'USD');
    }

    /**
     * Deja una fila en la bitácora. No cambia el estado: eso lo hace quien corresponda, con su propia
     * transacción; esto es sólo el rastro.
     */
    public function registrarEvento(
        string $origen,
        string $evento,
        array $payload = [],
        ?string $estadoAnterior = null,
        ?string $ip = null
    ): PagoEvento {
        return $this->eventos()->create([
            'origen' => $origen,
            'evento' => $evento,
            'payload' => $payload,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $this->estado,
            'ip' => $ip,
        ]);
    }
}
