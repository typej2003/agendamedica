<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory;

    protected $table = 'cities';

    protected $fillable = [
        'state_id',
        'name',
        'capital',
    ];

    protected $casts = [
        'capital' => 'boolean',
    ];

    public function state()
    {
        return $this->belongsTo(Estado::class, 'state_id');
    }

    public function medicalCenters()
    {
        return $this->hasMany(MedicalCenter::class, 'city_id');
    }
}