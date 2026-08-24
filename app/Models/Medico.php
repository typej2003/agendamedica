<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medico extends Model
{
    use HasFactory;

    protected $table = 'medicos';

    protected $fillable = [
        'user_id',
        'name',
        'lastname',
        'license_number',
        'phone',
        'email',
        'biography',
        'photo_path',
        'office_id',
        'consultation_fee',
        'is_active',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class, 'office_id');
    }

    public function specialties()
    {
        return $this->belongsToMany(Specialty::class, 'medico_specialty', 'medico_id', 'specialty_id');
    }

    public function medicalCenters()
    {
        return $this->belongsToMany(MedicalCenter::class, 'medico_medical_center', 'medico_id', 'medical_center_id');
    }
}