<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada del **libro mayor del saldo del médico** (futuro). Es inmutable: los créditos suman, los
 * débitos y ajustes restan. `disponible_el` es la fecha desde la que el crédito se puede liquidar (T+1).
 *
 * El saldo se calcula sumando; no se guarda un campo “saldo” que se pueda desincronizar. Si en una primera
 * versión no se usa, igual queda el registro para cuando existan las liquidaciones.
 */
class MovimientoDeSaldo extends Model
{
    public const CREDITO = 'credito';
    public const DEBITO = 'debito';
    public const AJUSTE = 'ajuste';

    public const TIPOS = [self::CREDITO, self::DEBITO, self::AJUSTE];

    protected $table = 'movimientos_de_saldo';

    protected $fillable = [
        'reg_medico', 'tipo', 'concepto', 'pago_id', 'liquidacion_id', 'monto_usd_e2',
        'saldo_inicial_usd_e2', 'saldo_final_usd_e2', 'disponible_el', 'autor_id', 'nota',
    ];

    protected $casts = [
        'monto_usd_e2' => 'integer',
        'saldo_inicial_usd_e2' => 'integer',
        'saldo_final_usd_e2' => 'integer',
        'disponible_el' => 'date',
    ];

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class, 'liquidacion_id');
    }
}
