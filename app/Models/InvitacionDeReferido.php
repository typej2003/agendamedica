<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **Una invitación por correo del programa de referidos** (O-50): a qué correo se le mandó, con qué
 * código, cuántas veces y si esa persona terminó registrándose.
 *
 * Es una fila por correo y código (`unique`), no un registro por envío: `veces` y `ultima_vez` guardan el
 * reenvío. No se borra: es el rastro de lo que salió y de lo que no.
 */
class InvitacionDeReferido extends Model
{
    protected $table = 'invitaciones_de_referido';

    protected $fillable = [
        'codigo_id', 'reg_medico', 'email', 'enviada_por', 'enviada_el', 'veces', 'ultima_vez', 'registrada_el',
    ];

    protected $casts = [
        'veces' => 'integer',
        'enviada_el' => 'datetime',
        'ultima_vez' => 'datetime',
        'registrada_el' => 'datetime',
    ];

    public function codigo(): BelongsTo
    {
        return $this->belongsTo(CodigoDeReferido::class, 'codigo_id');
    }
}
