<?php

// app/Models/MedicalCenter.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una **sede**: el lugar físico donde atiende un médico (hospital, clínica, módulo). Es el nivel que
 * la cita guarda en `cola.medical_center_id` —no el consultorio, ver esa migración— y por eso lo que
 * se da de baja es la sede entera, no una sala.
 *
 * A diferencia de `offices` y `office_schedules` (tablas nuevas de AppDDR), acá el nombre y la
 * dirección son los mismos para todos los médicos del lugar: **el lugar es de la clínica, el
 * consultorio es del médico** (decisión del 2026-10-09). La tabla legada `clinicas` del dump tiene
 * las sedes reales y sigue sin migrar (ver `ROADMAP.md`).
 */
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
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /** Las sedes que se siguen ofreciendo al agendar y al crear un consultorio. */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

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