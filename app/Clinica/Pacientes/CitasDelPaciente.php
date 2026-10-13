<?php

namespace App\Clinica\Pacientes;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\CitaDeAgenda;
use Illuminate\Support\Collection;

/**
 * Las citas de **un paciente** (`/clinica/pacientes/{paciente}/citas`): el eje inverso de la agenda y la
 * pantalla que reemplaza el botón "Cita x Paciente" del escritorio
 * (`w_hacer_cita.srw:1039` → `w_listado_pacientes_a_citas`, WEB-2.15).
 *
 * Es una lectura: no sabe de HTTP ni de HTML, y todo se filtra por el `reg_medico` del contexto —un
 * paciente de otro médico no se devuelve ni se confirma que exista—. Las filas se arman con
 * `ArmadorDeAgenda`, que es el único lugar donde una cita de `cola` se resuelve a `CitaDeAgenda` (nombre
 * del paciente, sede y razón): la pantalla no tiene su propia versión de esa lectura.
 *
 * Diferencia deliberada con el `DataWindow` de la fuente
 * (`d_pacientes_consulta_cita_medi_paci.srd`): aquel filtraba `cola.atendido <> 2`, o sea **escondía**
 * las citas que el escritorio movió de día. Acá vienen todas y la movida se muestra marcada
 * (`CitaDeAgenda::movida()`), que es la decisión de WEB-2.13.
 */
final class CitasDelPaciente
{
    public function __construct(private ArmadorDeAgenda $armador)
    {
    }

    /**
     * Todas las citas del paciente, de la más reciente a la más vieja.
     *
     * El paciente se busca por las dos formas en que una cita se enlaza con él: `numhistoria` —lo normal,
     * sale de `medico_pacientes`— o `paciente_sinhistoria_id` —una cita de un paciente al que todavía no
     * se le asignó número de historia—. Con las dos nulas no hay nada que buscar: devuelve vacío en vez
     * de traer todas las citas del médico.
     *
     * @return Collection<int,CitaDeAgenda>
     */
    public function todas(string $regMedico, ?int $numHistoria, ?int $pacienteId): Collection
    {
        return $this->armador->citasDelPaciente($regMedico, $numHistoria, $pacienteId);
    }
}
