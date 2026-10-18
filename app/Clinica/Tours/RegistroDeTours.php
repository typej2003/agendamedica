<?php

namespace App\Clinica\Tours;

use App\Models\TourVisto;

/**
 * **La memoria de los tours** (A-15): qué pantalla ya vio cada usuario, en el servidor.
 *
 * Es la única puerta a `tours_vistos`: la usa el compositor del armazón (para decidir si el tour se abre
 * solo) y el endpoint `POST /clinica/tour/{pantalla}` (que la marca al terminar o al saltar). Marcar es
 * idempotente: reabrir un tour y terminarlo mueve `visto_el`, no duplica la fila.
 */
final class RegistroDeTours
{
    public function yaVisto(int $usuarioId, string $pantalla): bool
    {
        return TourVisto::query()
            ->where('user_id', $usuarioId)
            ->where('pantalla', $pantalla)
            ->exists();
    }

    public function marcarVisto(int $usuarioId, string $pantalla): TourVisto
    {
        return TourVisto::query()->updateOrCreate(
            ['user_id' => $usuarioId, 'pantalla' => $pantalla],
            ['visto_el' => now()],
        );
    }
}
