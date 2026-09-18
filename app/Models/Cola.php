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
     * Estados de una cita, sobre la columna legada `estado` (DECIMAL(1,0)).
     *
     * El campo existe en el esquema legado pero estaba prácticamente sin usar (4 filas con `1`
     * de 19.906 en el dump real), así que se le fija acá la convención. Son **mutuamente
     * excluyentes** a propósito: el manual de Agenda Integral pinta un solo color por cita
     * (ver Docs/Wiki/02-modulo-agenda.md §5), o sea el legado ya las trataba como un estado
     * único, no como banderas combinables.
     *
     * "Atendida" (blanco en ese mismo cuadro) NO va acá: vive en `atendido`, que el legado sí
     * usa de verdad, y es una dimensión independiente — una cita puede estar pagada y atendida.
     *
     * ⚠️ `monto` es lo que se cobra por la cita. No hay "monto pagado"/"monto pendiente" para
     * citas en el esquema legado: los abonos parciales son de cirugías (`baremo_quiru`,
     * `pago_quiru`) y la facturación real es `factura_cliente` (Fase 3 del roadmap).
     */
    public const ESTADO_NO_CONFIRMADA = 0;       // amarillo
    public const ESTADO_CONFIRMADA = 1;          // azul — confirmada por el consultorio
    public const ESTADO_CONFIRMADA_PACIENTE = 2; // celeste — la confirmó el paciente
    public const ESTADO_PAGADA = 3;              // verde

    public const ESTADOS = [
        self::ESTADO_NO_CONFIRMADA,
        self::ESTADO_CONFIRMADA,
        self::ESTADO_CONFIRMADA_PACIENTE,
        self::ESTADO_PAGADA,
    ];

    protected $table = 'cola';

    protected $fillable = [
        'reg_medico',
        'fecha',
        'numhistoria',
        'paciente_sinhistoria_id',
        'numorden',
        'atendido',
        'estado',
        'turno',
        'motivo',
        'monto',
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
    ];

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