<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El **representante del paciente** — tabla legada (`representante`, una fila por `reg_medico` + `numhistoria`).
 *
 * Es el adulto que responde por el niño y **el que presta el teléfono** para el recordatorio y para los datos
 * obligatorios de pediatría (`DatosObligatoriosDelPaciente`). La web lo lee con `RepresentanteDelPaciente` y lo
 * escribe con `GuardarRepresentante`; el sync del escritorio sube la tabla entera y el app la baja y la edita
 * (2026-11-04), que es para lo que existe este modelo: el camino genérico de `updated` del sync necesita una
 * fila Eloquent para escribir una columna con su last-write-wins.
 *
 * `cedula`, `telefono` y `email` son columnas **nullable nuevas** de WEB-3.4, y `estado_civil` lo es de
 * O-59 (2026-11-05): el estado civil del adulto, con la misma escala que `pacientes.codeestado`
 * (`S`/`C`/`D`/`V`). El resto es del legado.
 */
class Representante extends Model
{
    protected $table = 'representante';

    protected $fillable = [
        'reg_medico', 'numhistoria', 'nombre', 'codeparentesco', 'cedula', 'telefono', 'email', 'direccion',
        'estado_civil',
    ];

    protected $casts = ['numhistoria' => 'integer'];
}
