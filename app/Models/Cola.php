<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Cola extends Model
{
    use HasFactory;

    /**
     * Una cita tiene **tres dimensiones independientes**, no un estado único: confirmación, pago
     * y atención. La pantalla del sistema legado las muestra como tres etiquetas separadas
     * ("Confirmado | No atendida | Pagado"), o sea una cita puede estar confirmada *y* pagada *y*
     * sin atender al mismo tiempo. Cada una vive en su propio campo:
     *
     * - **Confirmación** → `estado` (esta constante). El escritorio **no** la usa para esto: sus
     *   ventanas de agenda escriben siempre `0`, y el `1` se lo pone facturación (ver la nota de
     *   abajo). Acá la columna es la confirmación y esos son sus únicos valores.
     * - **Atención** → `atendido` (0/1), que el legado sí usa de verdad. ⚠️ En el escritorio la misma
     *   columna tiene **cuatro** valores (0/1/2/3: el `2` es "la cita se movió" y el `3` *Realizada*,
     *   sin escritor). Acá siempre es el booleano; la traducción la hace el sync
     *   (`App\Sync\Escritorio\AtencionCita`, WEB-2.13) y la cita movida queda en
     *   `movida_escritorio`.
     * - **Pago** → `monto` vs. `monto_pagado`, ver `estadoPago()`.
     *
     * ⚠️ **Ojo con `estado` en el escritorio.** Ahí la misma columna significa "factura elaborada"
     * (la escribe facturación; ver `App\Sync\Escritorio\EstadoCita` y WEB-2.12). La traducción entre
     * las dos semánticas la hace **solo la capa de sincronización**: acá `estado` es siempre la
     * confirmación. El dato del escritorio vive en `facturada_escritorio`.
     */
    public const ESTADO_NO_CONFIRMADA = 0;       // no confirmada
    public const ESTADO_CONFIRMADA = 1;          // la confirmó el consultorio
    public const ESTADO_CONFIRMADA_PACIENTE = 2; // la confirmó el propio paciente

    public const ESTADOS = [
        self::ESTADO_NO_CONFIRMADA,
        self::ESTADO_CONFIRMADA,
        self::ESTADO_CONFIRMADA_PACIENTE,
    ];

    /** Resultados posibles de `estadoPago()`. */
    public const PAGO_SIN_MONTO = 'sin_monto';
    public const PAGO_PENDIENTE = 'pendiente';
    public const PAGO_ABONADA = 'abonada';
    public const PAGO_PAGADA = 'pagada';

    protected $table = 'cola';

    protected $fillable = [
        'reg_medico',
        'fecha',
        'numhistoria',
        'medical_center_id',
        'paciente_sinhistoria_id',
        'numorden',
        'atendido',
        'movida_escritorio',
        'estado',
        'facturada_escritorio',
        'turno',
        'motivo',
        'monto',
        'monto_pagado',
        'hora_ini',
        'hora_fin',
        'tiempo',
        'tipo',
        'conse',
        'sms',
        'sms_text',
        'medico',
    ];

    protected $casts = [
        'fecha' => 'date',
        'movida_escritorio' => 'boolean',
        'facturada_escritorio' => 'boolean',
    ];

    /** Lo que falta por cobrar de esta cita. */
    public function montoPendiente(): float
    {
        return max(0.0, (float) ($this->monto ?? 0) - (float) ($this->monto_pagado ?? 0));
    }

    /**
     * Estado de pago derivado de los montos, no guardado — así un abono parcial no necesita un
     * campo aparte que se pueda desincronizar de las cifras.
     *
     * `sin_monto` existe porque en los datos reales `monto` viene NULL en casi todas las filas
     * (el médico no siempre le pone precio a la cita). Sin esta distinción, 19.900 citas legadas
     * se pintarían como deuda pendiente.
     */
    public function estadoPago(): string
    {
        $monto = (float) ($this->monto ?? 0);
        $pagado = (float) ($this->monto_pagado ?? 0);

        if ($monto <= 0) {
            return $pagado > 0 ? self::PAGO_PAGADA : self::PAGO_SIN_MONTO;
        }

        if ($pagado >= $monto) {
            return self::PAGO_PAGADA;
        }

        return $pagado > 0 ? self::PAGO_ABONADA : self::PAGO_PENDIENTE;
    }

    /**
     * Deja rastro en `sync_changes` antes de borrarse — es lo que le permite a
     * `SyncAppDataController::pull()` avisarle al cliente que esta fila ya no existe.
     */
    protected static function booted()
    {
        static::deleting(function (Cola $cola) {
            SyncChange::create([
                'reg_medico' => $cola->reg_medico,
                'table_name' => 'cola',
                'record_id' => $cola->id,
                'operation' => 'deleted',
                'occurred_at' => now(),
                'source' => 'api',
            ]);
        });
    }

    /**
     * Relación con el modelo MedicoRegistro mediante la clave reg_medico.
     */
    public function medicoRegistro(): BelongsTo
    {
        return $this->belongsTo(MedicoRegistro::class, 'reg_medico', 'reg_medico');
    }

    /**
     * Relación indirecta con el modelo Medico a través de MedicoRegistro.
     */
    public function medico(): HasOneThrough
    {
        return $this->hasOneThrough(
            Medico::class,
            MedicoRegistro::class,
            'reg_medico', // Clave foránea en medico_registros
            'id',         // Clave primaria en medicos
            'reg_medico', // Clave local en cola
            'medico_id'   // Clave foránea en medico_registros hacia medicos
        );
    }

    /**
     * Relación con la tabla pacientes mediante el número de historia.
     */
    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'numhistoria', 'numhistoria');
    }
}