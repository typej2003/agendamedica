<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $table = 'countries';

    protected $fillable = [
        'shortname',
        'name',
        'phonecode',
    ];

    public function states()
    {
        return $this->hasMany(Estado::class, 'country_id');
    }

    public function cities()
    {
        return $this->hasManyThrough(
            City::class,
            Estado::class,
            'country_id', // Clave foránea en la tabla 'estados'
            'state_id',   // Clave foránea en la tabla 'cities'
            'id',         // Clave local en 'countries'
            'id'          // Clave local en 'estados'
        );
    }
}