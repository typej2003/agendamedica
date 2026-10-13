<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;

/**
 * Una fila del panel **"Pacientes sin historia a atender"**: un paciente del día que todavía no tiene
 * número de historia, con la acción que se lo asigna ("Pasar a cita formal", WEB-2.11).
 *
 * Es la fila del `dw_3` de `w_hacer_cita` (`d_fecha_cita_paciente_no_registrado`, `:807-819`): el
 * escritorio la lee de `paciente_no_regi` con `registrado = 'N'`. AppDDR **no usa esa tabla** (deuda
 * del núcleo, anotada): la identidad "sin historia" es una cita anclada por
 * `cola.paciente_sinhistoria_id` y el vínculo `medico_pacientes` sin número.
 *
 * La fila solo traduce: qué paciente es, a qué hora y a dónde apunta la acción. Nada de reglas.
 */
final class FilaSinHistoriaVista
{
    public function __construct(private CitaDeAgenda $cita)
    {
    }

    public function id(): int
    {
        return $this->cita->id;
    }

    /** "Pérez, Ana María" — el nombre que ya resolvió el armador de la agenda. */
    public function paciente(): string
    {
        return $this->cita->paciente;
    }

    /** La ficha del paciente, que es donde la secretaría completa sus datos. */
    public function urlPaciente(): ?string
    {
        return $this->cita->pacienteId ? route('clinica.pacientes.ver', $this->cita->pacienteId) : null;
    }

    public function hora(): string
    {
        return $this->cita->horaCorta();
    }

    /** La razón de la cita, resuelta contra el catálogo. */
    public function razon(): string
    {
        return $this->cita->razon();
    }

    /**
     * "Pasar a cita formal": la ruta del `pb_3` de `w_hacer_cita` (`:801-805`) →
     * `w_pasar_pacientes_n_reg_consulta_cita`. El POST lo resuelve la Action compartida
     * (`App\Actions\Pacientes\AsignarHistoria`): el número lo asigna el servidor.
     */
    public function urlPasarACitaFormal(): string
    {
        return route('clinica.agenda.pasar-a-cita-formal', $this->cita->id);
    }

    /** Lo que se le dice al usuario en la confirmación, nombrando al paciente. */
    public function confirmar(): string
    {
        return '¿Pasar a cita formal la cita de ' . $this->paciente()
            . '? Se le asigna el número de historia de este consultorio y la cita queda enlazada a él.';
    }
}
