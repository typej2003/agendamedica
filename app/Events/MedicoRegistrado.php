<?php

namespace App\Events;

use App\Models\Medico;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se creó un médico (por el panel, el registro público o donde sea: lo dispara el modelo al crearse).
 * Lo escucha App\Listeners\OtorgarServicioDePrueba.
 */
class MedicoRegistrado
{
    use Dispatchable, SerializesModels;

    public function __construct(public Medico $medico)
    {
    }
}
