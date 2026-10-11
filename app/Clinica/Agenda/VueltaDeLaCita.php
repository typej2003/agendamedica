<?php

namespace App\Clinica\Agenda;

use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * **A dónde vuelve el formulario de la cita** cuando no se llegó desde la agenda (WEB-5.2): "Hacer cita" desde la
 * sección de récipes de una consulta abre el formulario de siempre con el paciente ya elegido y, al guardar,
 * **vuelve a esa sección** y no a la agenda.
 *
 * No agrega ninguna regla de agenda: sólo recuerda de qué consulta se venía (`volver_consulta`) y comprueba que sea
 * **una consulta del contexto y de quien puede entrar a ella** —la consulta es sólo del médico, así que la secretaría
 * no tiene a dónde volver y el parámetro se ignora—. Una consulta de otro consultorio, un valor que no es un número
 * o cualquier cosa rara dan `null`, y el formulario se comporta como siempre (vuelve a la agenda): el parámetro no
 * sirve ni para espiar consultas ajenas ni para redirigir a otro lado.
 */
final class VueltaDeLaCita
{
    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * @param  array<string,mixed>  $contexto  el contexto clínico
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto
     * @param  bool  $trabajaLaConsulta  si quien agenda puede entrar a la consulta (`User::trabajaLaConsulta()`)
     * @param  mixed  $consultaId  el `volver_consulta` que llegó (o no)
     * @return array{consulta:int, url:string, texto:string}|null
     */
    public function de(array $contexto, array $registrosMedicos, bool $trabajaLaConsulta, $consultaId): ?array
    {
        if (! $trabajaLaConsulta || ! is_scalar($consultaId) || ! ctype_digit((string) $consultaId)) {
            return null;
        }

        try {
            $consulta = $this->consultas->consulta($registrosMedicos, (int) $consultaId, $contexto['reg_medico']);
        } catch (ModelNotFoundException $excepcion) {
            return null;
        }

        return [
            'consulta' => (int) $consulta->id,
            'url' => route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES]),
            'texto' => 'Volver a los récipes',
        ];
    }
}
