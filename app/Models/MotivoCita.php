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
        'office_id',
        'codigo',
        'tipo_atencion',
        'precio',
    ];

    protected $casts = [
        'precio' => 'float',
    ];

    /**
     * Relación con el usuario/médico.
     */
    public function medico()
    {
        return $this->belongsTo(User::class, 'medico_id');
    }

    /**
     * La sede a la que pertenece este motivo. Nula en los motivos que vienen del sync de
     * PowerBuilder, que no conoce el concepto de sede (ver migración de `office_id`).
     */
    public function office()
    {
        return $this->belongsTo(Office::class, 'office_id');
    }
}