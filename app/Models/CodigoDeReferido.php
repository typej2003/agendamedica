<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El **código de referido de un consultorio**. Hay uno vigente por `reg_medico`; “regenerar” cambia el
 * código y el link viejo deja de atribuir.
 *
 * No guarda datos personales de quien abre el link: sólo el contador de clics y la fecha del último.
 */
class CodigoDeReferido extends Model
{
    protected $table = 'codigos_de_referido';

    protected $fillable = ['reg_medico', 'codigo', 'activo', 'clics', 'ultimo_clic_el'];

    protected $casts = [
        'activo' => 'boolean',
        'clics' => 'integer',
        'ultimo_clic_el' => 'datetime',
    ];

    public function referidos(): HasMany
    {
        return $this->hasMany(Referido::class, 'codigo_id');
    }
}
