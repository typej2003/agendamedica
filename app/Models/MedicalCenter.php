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
 * **La sede es de un médico** (`medico_id`, desde el 2026-10-26: «cada doctor tiene sus sedes», Alexander).
 * Antes era del catálogo de la clínica y todos veían las de todos. `medico_id` nulo es una sede vieja o
 * compartida: no le pertenece a nadie y sólo se ve por los consultorios que cada médico tenga en ella. El
 * **nombre es único por médico**, no global: dos médicos pueden tener cada uno su «CEMOC». La tabla legada
 * `clinicas` del dump tiene las sedes reales y sigue sin migrar (ver `ROADMAP.md`).
 */
class MedicalCenter extends Model
{
    use HasFactory;

    protected $fillable = [
        'medico_id',
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

    /** El médico **dueño** de la sede; `null` en las viejas o compartidas. */
    public function medico()
    {
        return $this->belongsTo(Medico::class);
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