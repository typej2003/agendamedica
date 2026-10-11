<?php

namespace App\Actions\Agenda;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Cola;
use App\Models\MedicoPaciente;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;

/**
 * Marcar una cita como atendida — o deshacer la marca.
 *
 * **Atender implica confirmado**: si la cita todavía no estaba confirmada, esta misma acción la
 * confirma por el consultorio. Es la regla del móvil (`Cola.atendiendo`), y tiene sentido de
 * negocio: si el paciente ya está siendo atendido, llegó — lo que la confirmación registra.
 *
 * Confirmación y atención son columnas distintas y se resuelven por separado (el sync es
 * last-write-wins **por columna**), por eso cada una pasa por su propio guard.
 *
 * Abrir la consulta es otra cosa y no vive acá: en el móvil `AccionesCita.atender` además abre la
 * consulta del día; en la web eso llegó con `AbrirConsulta` (WEB-4.1) y el mostrador lo llama antes.
 *
 * ## La regla "cita atendida ⇒ tiene historia" (WEB-4.8)
 *
 * **Una cita no se marca atendida si su paciente no tiene número de historia.** Es el arreglo que
 * [TODO.md](TODO.md) §9 dejó anotado y que PLAN-WEB.md §3.13 cerró: una cita `atendido = 1` con
 * `numhistoria` nulo deja una atención **sin número**, y el número es la llave de la historia clínica
 * (no hay dónde colgar sus motivos, diagnósticos ni documentos).
 *
 * Vive **acá y en un solo lugar** porque esta Action es el único camino a `atendido`: el mostrador de la
 * web (`MostradorController::atender`, los cuatro casos de WEB-4.1) y el sync del móvil
 * (`SyncAppDataController::aplicarAccionDeCita`, que delega en esta misma clase desde WEB-1.4). El app
 * ya la cumplía por su cuenta —su único camino a `atendido` es `AccionesCita.atender`, que abre la
 * consulta y con ella crea la historia—; lo que faltaba era que el **servidor** la exigiera.
 *
 * Dos matices que son parte de la regla:
 *
 *  - **Se resuelve antes de rechazar**: una cita anclada por `paciente_sinhistoria_id` cuyo vínculo
 *    **ya tiene** número se numera sola, en vez de rechazarse. Es el caso que TODO.md §9 describe como
 *    "la cita se creó con `paciente_temp_id` aunque el paciente ya tuviera historia" —la cita no se
 *    puede quedar sin número cuando el número existe—. El enlace se anota en `sync_changes` con su
 *    origen, igual que cualquier otra escritura, para que el last-write-wins lo pueda resolver.
 *  - **Deshacer la marca no la exige**: `atendida = false` libera la cita y no necesita historia. La
 *    regla es sobre lo que la atención significa, no sobre limpiarla.
 *
 * Quien la incumple recibe `CitaNoAtendible`; cada superficie lo traduce a lo suyo (la web, un aviso; el
 * sync del móvil, un cambio descartado), que es la diferencia entre una regla de negocio y un error de
 * formato (ver `CitaNoAtendible`).
 */
final class AtenderCita
{
    public function ejecutar(Cola $cita, bool $atendida = true, ?Carbon $cuando = null, string $origen = 'web'): bool
    {
        $cuando = $cuando ?: Carbon::now();
        $aplicado = false;

        if ($atendida && (int) $cita->estado === Cola::ESTADO_NO_CONFIRMADA) {
            $confirmo = (new ConfirmarCita())->ejecutar($cita, Cola::ESTADO_CONFIRMADA, $cuando, $origen);
            $aplicado = $confirmo || $aplicado;
        }

        if (! RegistroDeCambios::permite('cola', $cita->id, 'atendido', $cuando)) {
            return $aplicado;
        }

        if ($atendida && $cita->numhistoria === null) {
            $this->enlazarLaHistoria($cita, $cuando, $origen);
        }

        $cita->atendido = $atendida ? 1 : 0;
        $cita->save();

        RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, 'atendido', (int) $cita->atendido, $cuando, $origen);

        return true;
    }

    /**
     * Le pone a la cita la historia que su paciente **ya tiene**, o lanza `CitaNoAtendible`.
     *
     * El vínculo del paciente (`medico_pacientes`) puede tener el número en cualquiera de los
     * `reg_medico` del médico, pero se prefiere el de la propia cita: es el registro con el que el
     * escritorio y el sync la van a leer. El número se guarda como texto en el pivote y como entero en
     * `cola`, así que se limpia antes de escribirlo (`CamposDePaciente::historiaNumerica`: los datos del
     * legado traen `''`, espacios y basura como `S/H`).
     *
     * @throws CitaNoAtendible
     */
    private function enlazarLaHistoria(Cola $cita, Carbon $cuando, string $origen): void
    {
        $numero = $this->historiaDelPacienteDeLaCita($cita);

        if ($numero === null) {
            throw CitaNoAtendible::sinHistoria();
        }

        $cita->numhistoria = $numero;
        $cita->save();

        // La columna se anota como cualquier otra escritura de la web: sin el rastro, una edición vieja
        // del teléfono llegaría después sin con qué compararse y pisaría el enlace (R3 de PLAN-WEB.md).
        RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, 'numhistoria', $numero, $cuando, $origen);
    }

    /**
     * El número de historia del paciente de la cita, o `null` si todavía no tiene ninguna.
     *
     * Se busca por el **pivote** y no por `historias.paciente_id`, que es la misma vara que
     * `App\Clinica\Consulta\PacienteDeLaCita`: el pivote es el que dice de quién es el paciente en este
     * consultorio y el que `AsignarHistoria` numera.
     */
    private function historiaDelPacienteDeLaCita(Cola $cita): ?int
    {
        $pacienteId = $cita->paciente_sinhistoria_id;
        if ($pacienteId === null) {
            return null;
        }

        $vinculos = MedicoPaciente::where('paciente_id', (int) $pacienteId)
            ->where('reg_medico', $cita->reg_medico)
            ->get(['numhistoria', 'reg_medico']);

        foreach ($vinculos as $vinculo) {
            $numero = CamposDePaciente::historiaNumerica($vinculo->numhistoria);

            if ($numero !== null) {
                return $numero;
            }
        }

        return null;
    }
}
