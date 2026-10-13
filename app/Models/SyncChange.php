<?php

namespace App\Models;

use App\Sync\UsuarioDelRastro;
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
        'client_temp_id',
        'operation',
        'column_name',
        'value',
        'occurred_at',
        'source',
        'user_id',
    ];

    protected $casts = [
        'record_id' => 'integer',
        'client_temp_id' => 'integer',
        'occurred_at' => 'datetime',
        'user_id' => 'integer',
    ];

    /**
     * **Quién escribió** (WEB-8.5, la bitácora): toda fila con `source = web` —y las de `api` que dejan `Cola` y `Paciente` al
     * borrarse, que desde la web también salen de una sesión— anota la cuenta de la sesión web. Se hace acá, en el único
     * punto por el que pasan todos los escritores, y no en cada Action: una Action futura que deje su rastro por el modelo lleva
     * el usuario sin acordarse de él. Fuera de una sesión web (el móvil, el escritorio, la consola) queda `null`.
     * Un `user_id` ya puesto a mano no se pisa.
     */
    protected static function booted()
    {
        static::creating(function (SyncChange $cambio) {
            if ($cambio->user_id === null && in_array($cambio->source, ['web', 'api'], true)) {
                $cambio->user_id = UsuarioDelRastro::actual();
            }
        });
    }
}
