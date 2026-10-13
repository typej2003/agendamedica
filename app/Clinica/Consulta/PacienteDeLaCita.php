<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\MedicoPaciente;

/**
 * **De quién es la consulta**: a qué paciente y a qué historia pertenece una cita.
 *
 * Las dos preguntas se resuelven juntas y en un solo lugar porque la respuesta está repartida en tres
 * columnas del esquema del legado (`Docs/Wiki/04-historia-clinica.md`, "Anomalía a no repetir"): la cita
 * se enlaza con el paciente por `cola.numhistoria` (el camino normal, contra el pivote
 * `medico_pacientes`) o por `cola.paciente_sinhistoria_id` (el alta rápida sin número, WEB-2.11), y la
 * historia aparece recién cuando el pivote tiene `numhistoria`.
 *
 * Lo usan las tres pantallas que entran a una consulta: la Action `AbrirConsulta` al abrirla, el
 * mostrador al decidir si hay que mandar a asignar la historia (y cuál es la consulta del día que se
 * ofrece re-atender) y el shell al resolver el encabezado de una consulta ya existente. Si cada una
 * hiciera su propia lectura, el mismo paciente podría resolverse distinto en dos partes del mismo flujo.
 *
 * Su hermana es `CitaDeLaConsulta`, que va en el sentido contrario (de la consulta a la cita) y la usa el
 * cierre de la consulta (WEB-4.8).
 */
final class PacienteDeLaCita
{
    /**
     * `pacientes.id` del paciente de la cita, o `null` si la cita no dice a quién atender: las filas de
     * `cola` del legado con `numhistoria` nulo y sin ancla no tienen a quién numerarle la historia.
     */
    public function opcional(Cola $cita, array $registrosMedicos): ?int
    {
        if ($cita->numhistoria !== null) {
            $pacienteId = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where('numhistoria', (string) (int) $cita->numhistoria)
                ->value('paciente_id');

            if ($pacienteId !== null) {
                return (int) $pacienteId;
            }
        }

        return $cita->paciente_sinhistoria_id !== null ? (int) $cita->paciente_sinhistoria_id : null;
    }

    /**
     * La historia del paciente **en este consultorio**, o `null` si todavía no tiene número.
     *
     * Se busca por el **pivote del paciente** y no por `historias.paciente_id`: el pivote es el que dice
     * de quién es el paciente acá y el que `AsignarHistoria` numera. El `numhistoria` del pivote es
     * **texto** y puede venir vacío, con espacios o con basura (`S/H`): se limpia con `CamposDePaciente`
     * y, si no es numérico, no hay historia que buscar — castear a ciegas traería la historia `0`, que es
     * de otro paciente (wiki `04-historia-clinica.md`).
     *
     * @param  array<int,string>  $registrosMedicos  todos los `reg_medico` del médico, porque el pivote
     *                                               puede tener la historia en otro de sus registros
     */
    public function historia(int $pacienteId, array $registrosMedicos): ?Historia
    {
        $vinculo = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
            ->where('paciente_id', $pacienteId)
            ->first(['numhistoria', 'reg_medico']);

        $numero = CamposDePaciente::historiaNumerica($vinculo->numhistoria ?? null);

        if ($numero === null) {
            return null;
        }

        // El registro de la fila manda: es el que la cita y el escritorio esperan encontrar.
        $regMedico = CamposDePaciente::texto($vinculo->reg_medico ?? null);

        return Historia::where('numhistoria', (string) $numero)
            ->when($regMedico !== null, fn ($consulta) => $consulta->where('reg_medico', $regMedico))
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();
    }
}
