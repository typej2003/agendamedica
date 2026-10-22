<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La cédula confirmada por el personal del consultorio (nivel 2). Tabla propia: `pacientes` se sincroniza con el escritorio. */
class PacienteIdentidad extends Model
{
    protected $table = 'paciente_identidad';

    protected $guarded = [];

    protected $casts = ['cedula_confirmada_en' => 'datetime'];

    public function paciente()
    {
        return $this->belongsTo(Paciente::class);
    }

    public function confirmadaPor()
    {
        return $this->belongsTo(User::class, 'confirmada_por_user_id');
    }
}
