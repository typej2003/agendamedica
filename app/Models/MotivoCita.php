<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivoCita extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla asociada al modelo.
     *
     * @var string
     */
    protected $table = 'motivo_cita';

    /**
     * Atributos asignables en masa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'medico_id',
        'reg_medico',
        'codigo',
        'tipo_atencion',
    ];

    /**
     * Relación con el usuario/médico.
     */
    public function medico()
    {
        return $this->belongsTo(User::class, 'medico_id');
    }
}