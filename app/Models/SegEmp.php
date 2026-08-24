<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SegEmp extends Model
{
    use HasFactory;

    protected $table = 'seg_emps';
    protected $primaryKey = 'codesegemp';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'codesegemp',
        'nombre',
        'rif',
        'direccion',
        'telef',
    ];

    public function pacientes(): HasMany
    {
        return $table = $this->hasMany(Paciente::class, 'codesegemp', 'codesegemp');
    }
}