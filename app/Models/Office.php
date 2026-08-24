<?php

// app/Models/Office.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Office extends Model
{
    protected $fillable = ['medical_center_id', 'office_number', 'phone', 'schedule'];

    public function medicalCenter()
    {
        return $this->belongsTo(MedicalCenter::class);
    }

    public function medicos()
    {
        return $this->hasMany(Medico::class);
    }
}