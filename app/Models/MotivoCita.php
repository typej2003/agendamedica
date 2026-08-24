<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MotivoCita extends Model
{
    use HasFactory;

    protected $table = 'motivo_citas';
    protected $primaryKey = 'codigo';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'codigo',
        'tipo_atencion',
    ];

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'tipo', 'codigo');
    }
}