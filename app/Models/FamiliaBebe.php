<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La **familia del niño** — tabla legada (`familia_bebe`, una fila por `familia` y consultorio).
 *
 * Guarda a los padres, sus teléfonos (`celu_madre`, `cel_padre`, `telefono`) y la cédula del representante.
 * Es el respaldo de `TelefonoDeContacto` cuando la fila de `representante` no tiene teléfono, y el app la baja
 * como espejo de solo lectura desde el 2026-11-04 para poder decidir lo mismo sin conexión (pediatría mínima).
 */
class FamiliaBebe extends Model
{
    protected $table = 'familia_bebe';

    protected $casts = ['familia' => 'integer'];
}
