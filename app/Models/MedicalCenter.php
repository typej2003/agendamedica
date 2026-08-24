<?php

// app/Models/MedicalCenter.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicalCenter extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'state_id',
        'city_id',
        'name',
        'address',
        'phone',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function offices()
    {
        return $this->hasMany(Office::class);
    }
}