<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
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
 * consulta del día; en la web eso llega con el módulo Consulta (PLAN-WEB.md, F4).
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

        $cita->atendido = $atendida ? 1 : 0;
        $cita->save();

        RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, 'atendido', (int) $cita->atendido, $cuando, $origen);

        return true;
    }
}
