<?php

namespace App\Http\Controllers\Clinica\Concerns;

use App\Models\Cola;
use Illuminate\Http\Request;

/**
 * Lo que comparten los controladores de la agenda para tocar **una cita**: que sea del `reg_medico` del
 * contexto (la de otro médico no se toca ni se confirma que exista) y el aviso de la cita que el
 * escritorio movió a otro día.
 */
trait ResuelveLaCitaDelContexto
{
    /** Una cita del `reg_medico` del contexto; la de otro médico responde 404. */
    private function citaDelContexto(Request $request, Cola $cola): Cola
    {
        $contexto = $request->attributes->get('contexto_clinico');
        abort_unless($cola->reg_medico === $contexto['reg_medico'], 404);

        return $cola;
    }

    private function avisoDeCitaMovida(): string
    {
        return 'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.';
    }
}
