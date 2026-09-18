<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por operación de sync (ver la migración `create_sync_changes_table` para el porqué del
 * shape): `created`/`deleted` marcan la fila entera, `updated` lleva una fila por columna cambiada.
 */
class SyncChange extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'sync_changes';

    protected $fillable = [
        'reg_medico',
        'table_name',
        'record_id',
        'operation',
        'column_name',
        'value',
        'occurred_at',
        'source',
    ];

    protected $casts = [
        'record_id' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
