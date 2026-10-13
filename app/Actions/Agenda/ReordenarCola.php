<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mueve una cita dentro de su jornada corriendo las demás.
 *
 * **El movimiento, no el resultado.** El cliente podría mandar el número de orden nuevo de cada
 * fila desplazada, pero mover una cita en un día de 20 serían 20 cambios para un gesto, 20 UPDATE
 * y —lo importante— 20 resoluciones de conflicto independientes: dos secretarias reordenando a la
 * vez se pisarían fila por fila y el resultado no sería el de ninguna. Mandando el movimiento, el
 * desplazamiento son dos sentencias (`UPDATE … WHERE … BETWEEN`) y dos reordenamientos
 * concurrentes se componen.
 *
 * La regla está **portada del móvil** (`reorden.dart`, `aplicarReorden`): la fila movida toma el
 * lugar destino y las que quedan entre medio suben o bajan uno. La usan la web (arrastre) y el
 * sync (`operation: reorder`), así que la base no puede quedar con un orden y la pantalla con otro.
 *
 * Funciona con numeraciones con huecos (los datos legados las tienen): la fila movida libera su
 * lugar, así que el corrimiento nunca colisiona.
 *
 * ⚠️ El alcance del corrimiento es **la fecha**, que es lo que declara el contrato del sync
 * (`SyncAppDataRequest::ORDER_SCOPE_COLUMN`), aunque `numorden` se numere por jornada
 * (fecha + sede + bloque). Se respeta tal cual para no separar la web del móvil; si dos jornadas
 * del mismo día comparten rango de `numorden`, el corrimiento las toca a las dos. Anotado como
 * deuda, no arreglado de paso (ver el guardarraíl del TODO-WEB).
 */
final class ReordenarCola
{
    /**
     * @param  int  $desde  Posición de origen. **La manda el cliente** en el sync (es su visión del
     *                      movimiento, que puede componer con otros); la web manda el `numorden`
     *                      de la cita que se arrastró.
     * @param  int  $hasta  Posición destino (el `numorden` de la cita sobre la que se soltó).
     * @param  string  $fecha  Día dentro del cual se ordena (`Y-m-d`).
     * @param  array<int,string>  $registrosMedicos  Registros del tenant que se corren (tenancy del sync).
     * @return bool  `false` si no había nada que mover.
     */
    public function ejecutar(
        Cola $cita,
        int $desde,
        int $hasta,
        string $fecha,
        array $registrosMedicos,
        ?Carbon $cuando = null,
        string $origen = 'web'
    ): bool {
        if ($desde === $hasta) {
            return false;
        }

        // En una transacción: entre el corrimiento y el movimiento de la fila, el orden está a
        // medio aplicar y nadie debería leerlo así.
        DB::transaction(function () use ($cita, $desde, $hasta, $fecha, $registrosMedicos) {
            $grupo = fn () => Cola::whereIn('reg_medico', $registrosMedicos)
                ->whereDate('fecha', $fecha)
                ->where('id', '!=', $cita->id);

            if ($hasta < $desde) {
                $grupo()->whereBetween('numorden', [$hasta, $desde])->increment('numorden');
            } else {
                $grupo()->whereBetween('numorden', [$desde, $hasta])->decrement('numorden');
            }

            $cita->numorden = $hasta;
            $cita->save();
        });

        RegistroDeCambios::registrarOperacion(
            'cola',
            $cita->id,
            $cita->reg_medico,
            'reorder',
            'numorden',
            "{$desde}->{$hasta}",
            $cuando ?: Carbon::now(),
            $origen
        );

        return true;
    }
}
