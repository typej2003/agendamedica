<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Consulta extends Model
{
    use HasFactory;

    protected $table = 'consultas';

    protected $fillable = [
        'medico_id',
        'reg-medico',
        'numhistoria',
        'nroconsulta',
        'fecha',
        'enfermedadactual',
        'peso',
        'talla',
        'fc',
        'pp',
        'circcefalica',
        'circtoraxica',
        'circabdominal',
        'tasentado',
        'taacostado',
        'tapie',
        'resultadoexamencomp',
        'eliminado',
        'faringe',
        'nariz',
        'oido',
        'laringe',
        'cuello',
        'otros',
        'evolucion',
        'observaciones',
        'medico',
        'sms',
    ];

    protected $casts = [
        'fecha' => 'date',
        'peso' => 'double',
        'talla' => 'double',
        'fc' => 'double',
        'pp' => 'double',
        'circcefalica' => 'double',
        'circtoraxica' => 'double',
        'circabdominal' => 'double',
        'numhistoria' => 'integer',
        'nroconsulta' => 'integer',
        'medico' => 'integer',
    ];

    /**
     * Relación con el Médico (Usuario).
     */
    public function medicoUsuario()
    {
        return $this->belongsTo(User::class, 'medico_id');
    }
}