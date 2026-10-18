<?php

namespace App\Clinica\Pacientes;

use App\Models\Specialty;

/**
 * **Lo que falta de la filiación de un paciente para cerrar su consulta** (WEB-11.25): junta lo que `DatosObligatoriosDelPaciente`
 * necesita —la especialidad del contexto y el teléfono del representante— y le pregunta qué falta. Lo usan la sección «Datos personales»
 * (para avisar) y el cierre de la consulta (para impedirlo): una sola lectura, para que los dos digan lo mismo.
 */
final class FiliacionPendiente
{
    /**
     * @param  object  $paciente  con `nombres`, `apellidos`, `fnacimiento` y `telefono`
     * @return array<int,string>
     */
    public static function de(object $paciente, string $regMedico, ?string $numhistoria, ?int $specialtyId): array
    {
        $slug = $specialtyId === null ? null : Specialty::whereKey($specialtyId)->value('slug');
        $representante = (new RepresentanteDelPaciente())->de($regMedico, $numhistoria);

        // El teléfono del representante, o el de la familia del escritorio de pediatría (WEB-7.4.2c).
        $telefono = CamposDePaciente::texto($representante->telefono ?? null) ?? (new TelefonoDeContacto())->de($regMedico, $numhistoria);

        return DatosObligatoriosDelPaciente::faltantes($paciente, $slug, $telefono);
    }
}
