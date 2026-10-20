<?php

namespace App\Actions\Agenda;

use App\Clinica\Agenda\MedicosDeLaCita;
use App\Models\Cola;
use App\Models\Medico;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * **Ponerle el doctor a una cita** (Fase 3 del cambio de `reg_medico`, 2026-10-20).
 *
 * La cita del centro nace a nombre del centro y **sin doctor** (`cola.medico_id = null`): el doctor se
 * elige **antes de la consulta**, desde la agenda (la secretaría) o al atenderla (el médico). Esta Action es
 * **el único lugar** donde se escribe el doctor de una cita, y por eso las dos reglas viven acá:
 *
 *  - **el doctor tiene que atender en ese consultorio**: sale de los `medico_registros` del `reg_medico` de
 *    la cita (más su dueño si es un consultorio personal). Nunca un doctor ajeno ni el centro, que no
 *    atiende;
 *  - **una cita ya atendida no cambia de doctor**: la atención ya ocurrió (y su consulta y sus récipes se
 *    firmaron con ese médico).
 *
 * Escribe `medico_id` (la fuente de verdad) **y** `medico`, la clave legacy de `evolucion` que el
 * escritorio sigue leyendo, con el last-write-wins por columna y el rastro en `sync_changes` de
 * `EditarCita`, que es la Action de la edición in-place de una cita.
 *
 * `tomar()` es el camino del «Atender»: **sólo** actúa si la cita no tiene doctor y **no falla nunca** —si
 * el médico que atiende no está vinculado al consultorio, la cita se queda como está y la consulta se abre
 * igual—, porque no poder guardar el nombre del doctor no puede impedir la atención.
 */
final class AsignarMedicoACita
{
    public function __construct(
        private MedicosDeLaCita $medicos,
        private EditarCita $editar,
    ) {
    }

    /**
     * Asignar (o reasignar) el doctor de una cita.
     *
     * @return array{medico: Medico, aplicadas: list<string>} el doctor y las columnas que cambiaron (vacío si
     *                                                        la cita ya era de él)
     *
     * @throws InvalidArgumentException si la cita ya está atendida o el doctor no atiende en el consultorio
     */
    public function ejecutar(Cola $cita, int $medicoId, Carbon $cuando, string $origen = 'web'): array
    {
        if ((int) $cita->atendido === 1) {
            throw new InvalidArgumentException('Una cita ya atendida no cambia de médico.');
        }

        $doctor = $this->medicos->asignable((string) $cita->reg_medico, $medicoId);

        if ($doctor === null) {
            throw new InvalidArgumentException('Ese médico no atiende en este consultorio: elige uno de los doctores que trabajan en él.');
        }

        return ['medico' => $doctor, 'aplicadas' => $this->escribir($cita, $doctor, $cuando, $origen)];
    }

    /**
     * **El doctor que atiende se queda con la cita**: la llama `AtenderCita`. Si la cita ya tiene doctor no
     * la toca (no cambia de médico) y si el doctor no atiende en ese consultorio tampoco (no falla: la
     * atención sigue).
     */
    public function tomar(Cola $cita, int $medicoId, Carbon $cuando, string $origen = 'web'): void
    {
        if ($cita->medico_id !== null) {
            return;
        }

        $doctor = $this->medicos->asignable((string) $cita->reg_medico, $medicoId);

        if ($doctor === null) {
            return;
        }

        $this->escribir($cita, $doctor, $cuando, $origen);
    }

    /**
     * Las dos columnas del doctor, en una sola edición (un guardado y su rastro por columna).
     *
     * La clave legacy se busca en **los registros del doctor** (el suyo y los centros donde trabaja), igual
     * que al agendar: la fila de `evolucion` del médico vive bajo su registro, no necesariamente bajo el del
     * centro donde atiende.
     *
     * @return list<string>
     */
    private function escribir(Cola $cita, Medico $doctor, Carbon $cuando, string $origen): array
    {
        return $this->editar->ejecutar($cita, [
            'medico_id' => (int) $doctor->id,
            'medico'    => $doctor->claveDeEvolucion($doctor->registrosDeTrabajo()),
        ], $cuando, $origen);
    }
}
