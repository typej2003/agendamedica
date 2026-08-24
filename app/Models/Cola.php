<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cola extends Model
{
    use HasFactory;

    protected $table = 'colas';
    
    public $incrementing = false;
    protected $primaryKey = ['fecha', 'numhistoria'];

    protected $fillable = [
        'fecha',
        'hora_ini',
        'numhistoria',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'numhistoria', 'numhistoria');
    }
}