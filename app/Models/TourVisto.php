<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un **tour guiado ya visto** por un usuario en una pantalla concreta (A-15).
 *
 * Tabla nueva de AppDDR (`tours_vistos`), no del legado: no sincroniza. La clave es la pareja
 * `user_id` + `pantalla`, y `visto_el` dice cuándo lo terminó (o lo saltó) para no volver a mostrarlo.
 */
class TourVisto extends Model
{
    protected $table = 'tours_vistos';

    /** La tabla es sólo el registro de la marca: no lleva `created_at`/`updated_at`. */
    public $timestamps = false;

    protected $fillable = ['user_id', 'pantalla', 'visto_el'];

    protected $casts = [
        'visto_el' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
