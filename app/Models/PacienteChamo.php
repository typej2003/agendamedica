<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El **niño del consultorio de pediatría** — tabla legada (`paciente_chamo`, una fila por `historia`).
 *
 * Es el ABM real de pacientes de PediaReport: guarda nombre, nacimiento, sexo, cédula del niño y **a qué
 * `familia` pertenece** (`familia_bebe`), que es de donde salen los teléfonos de la madre, el padre y el
 * representante. La web lo lee con `FamiliaDelPaciente` y `TelefonoDeContacto`; el app lo baja como espejo de
 * solo lectura desde el 2026-11-04 para resolver el mismo teléfono sin conexión (pediatría mínima).
 *
 * El paciente de AppDDR (`pacientes`) se crea a partir de esta tabla en la carga inicial del escritorio; acá
 * el teléfono es el del adulto, no el del niño.
 */
class PacienteChamo extends Model
{
    protected $table = 'paciente_chamo';

    protected $casts = ['familia' => 'integer', 'historia' => 'integer'];
}
