<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Medico extends Model
{
    use HasFactory;

    protected $table = 'medicos';

    protected $fillable = [
        'user_id', // Relación con la tabla de usuarios modelo User, cuando ya tiene un registro en User
        'name',
        'lastname',
        'license_number',
        'phone',
        'email',
        'password',
        'biography',
        'photo_path',
        'office_id',
        'consultation_fee',
        'is_active',
        'reg-medico', // temporal, tiende a cambiar no usar
    ];

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'office_id');
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'medico_specialty', 'medico_id', 'specialty_id');
    }

    public function medicalCenters(): BelongsToMany
    {
        return $this->belongsToMany(MedicalCenter::class, 'medico_medical_center', 'medico_id', 'medical_center_id');
    }

    public function pacientes(): BelongsToMany
    {
        return $this->belongsToMany(Paciente::class, 'medico_pacientes', 'medico_id', 'paciente_id')
                    ->using(MedicoPaciente::class)
                    ->withPivot('medico_id', 'paciente_id')
                    ->withTimestamps();
    }
}