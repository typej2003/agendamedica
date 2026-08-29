<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paciente extends Model
{
    use HasFactory;

    protected $table = 'pacientes';

    protected $fillable = [
        'user_id', // Relación con la tabla de usuarios modelo User, cuando ya tiene un registro en User
        'numhistoria',
        'nac',
        'cedula',
        'apellidos',
        'nombres',
        'sexo',
        'fnacimiento',
        'lnacimiento',
        'codeestado',
        'direccion',
        'telefono',
        'fingreso',
        'escolaridad',
        'ocupacion',
        'codesegemp',
        'foto_pac',
        'profesion',
        'email',
        'password',
        'dependencia',
        'medico',
        'sms',
    ];

    protected $casts = [
        'fnacimiento' => 'date',
        'fingreso' => 'date',
    ];

    /**
     * Relación con las historias médicas del paciente.
     */
    public function historias(): HasMany
    {
        return $this->hasMany(Historia::class, 'paciente_id', 'id');
    }

    /**
     * Relación con las consultas médicas del paciente.
     */
    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class, 'paciente_id', 'id');
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_pacientes', 'paciente_id', 'medico_id')
                    ->using(MedicoPaciente::class)
                    ->withPivot('numhistoria')
                    ->withTimestamps();
    }

    public function segEmp(): BelongsTo
    {
        return $this->belongsTo(SegEmp::class, 'codesegemp', 'codesegemp');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'paciente_id', 'id');
    }

    public function colas(): HasMany
    {
        return $this->hasMany(Cola::class, 'paciente_id', 'id');
    }
}