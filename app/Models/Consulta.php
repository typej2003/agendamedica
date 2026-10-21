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
        'reg_medico',
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
        // La columna legacy: la **clave** de `evolucion` con la que el escritorio identifica al doctor (no es
        // `medicos.id`; ver `Medico::claveDeEvolucion()`).
        'medico' => 'integer',
        // El `medicos.id` del **doctor que atendió** (columna nueva y nullable, 2026-10-20). Es la fuente de verdad
        // de `App\Clinica\Impresion\MedicoQueFirma`; la escribe `CrearConsulta`.
        'medico_id' => 'integer',
    ];

    /**
     * Relación con el Médico (Usuario).
     *
     * ⚠️ Quedó de antes y **no se usa** (ni la web ni el móvil la leen): apunta `medico_id` a
     * `users`, pero esa columna es un `medicos.id` desde que la consulta guarda su doctor. Se deja como estaba —no
     * se renombra ni se reescribe en esta tarea— para no tocar nada de paso; lo correcto sería un
     * `belongsTo(Medico::class, 'medico_id')`.
     */
    public function medicoUsuario()
    {
        return $this->belongsTo(User::class, 'medico_id');
    }
}