<?php

namespace App\Clinica\Vacunas;

use App\Models\Paciente;

/**
 * **El aviso de vacunas pendientes al atender** (WEB-7.4.4d): un banner discreto en la consulta del niño con cuántas dosis tiene **atrasadas** y
 * **próximas** (en los próximos 30 días), según el calendario del consultorio. No es la alerta de antecedentes (esa es roja y abre un diálogo) y
 * **no se muestra en la agenda de la secretaría** (misma regla que las alertas clínicas: sólo está en la consulta, que es del médico).
 *
 * Devuelve `null` —y la consulta no pinta nada— si el médico no tiene vacunas en su catálogo, si el niño no tiene fecha de nacimiento o si no hay
 * ninguna dosis atrasada ni próxima. Sólo lee.
 */
class AvisoDeVacunas
{
    public function __construct(private VacunasDelPaciente $lectura, private CalendarioDeVacunas $calendario)
    {
    }

    /**
     * @return array{atrasadas:int, proximas:int, texto:string}|null
     */
    public function de(string $regMedico, int $pacienteId, ?int $numhistoria, string $hoy): ?array
    {
        $paciente = Paciente::find($pacienteId);

        if ($paciente === null || empty($paciente->fnacimiento) || $numhistoria === null) {
            return null;
        }

        $datos = $this->lectura->de($regMedico, $numhistoria);

        if ($datos['vacunas'] === []) {
            return null;
        }

        $resumen = $this->calendario->armar(
            $paciente->fnacimiento->toDateString(), $paciente->sexo, $hoy, $datos['vacunas'], $datos['dosis'], $datos['aplicadas'],
        )['resumen'];

        $atrasadas = $resumen[CalendarioDeVacunas::ATRASADA];
        $proximas = $resumen[CalendarioDeVacunas::PROXIMA];

        if ($atrasadas + $proximas === 0) {
            return null;
        }

        $partes = [];
        if ($atrasadas > 0) {
            $partes[] = $atrasadas . ($atrasadas === 1 ? ' dosis atrasada' : ' dosis atrasadas');
        }
        if ($proximas > 0) {
            $partes[] = $proximas . ($proximas === 1 ? ' próxima' : ' próximas');
        }

        return ['atrasadas' => $atrasadas, 'proximas' => $proximas, 'texto' => 'Vacunas: ' . implode(' y ', $partes) . '.'];
    }
}
