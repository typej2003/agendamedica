<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Confirmar una cita — o quitarle la confirmación.
 *
 * La regla vive **acá y en un solo lugar**, y la usan las dos superficies: la web (el botón del
 * mostrador) y el sync del móvil (`updated` sobre `cola.estado`). Es lo que evita que la misma base
 * se comporte distinto según quién escriba (PLAN-WEB.md, R3).
 *
 * La confirmación es una de las **tres dimensiones independientes** de una cita (confirmación,
 * atención y pago, ver `App\Models\Cola`): `1` = la confirmó el consultorio, `2` = la confirmó el
 * paciente, `0` = sin confirmar. "Confirmar" desde la secretaría escribe `1`.
 *
 * Acá **no** se valida la ventana de tiempo (`Cola::puedeConfirmar`): eso es una ayuda de interfaz
 * ("recién una hora antes, y ese día"), no una invariante del dato. El sync llega con un
 * `occurred_at` del momento en que se tocó el teléfono, que puede ser horas antes, y una regla de
 * "ahora" lo rechazaría por un cambio legítimo.
 */
final class ConfirmarCita
{
    /**
     * @param  int  $estado  Uno de `Cola::ESTADOS`.
     * @param  Carbon|null  $cuando  Momento de la edición (el del cliente en el sync).
     * @param  string  $origen  `web` o `mobile`: quién la escribió.
     * @return bool  `false` si la descartó el last-write-wins (había una edición más nueva).
     */
    public function ejecutar(Cola $cita, int $estado, ?Carbon $cuando = null, string $origen = 'web'): bool
    {
        if (! in_array($estado, Cola::ESTADOS, true)) {
            throw new InvalidArgumentException("Estado de confirmación inválido: {$estado}.");
        }

        $cuando = $cuando ?: Carbon::now();

        if (! RegistroDeCambios::permite('cola', $cita->id, 'estado', $cuando)) {
            return false;
        }

        $cita->estado = $estado;
        $cita->save();

        RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, 'estado', $estado, $cuando, $origen);

        return true;
    }
}
