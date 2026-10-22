<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\ConsultaSesion;

/**
 * Quita el candado suave de una consulta (`consulta_sesiones`): se llama al **terminar** la consulta, para que el
 * próximo dispositivo que la abra no reciba el aviso «activa en otro dispositivo». Si no se llama, el candado
 * vence solo (`RegistrarSesionDeConsulta::VENTANA_SEGUNDOS`).
 */
final class LiberarSesionDeConsulta
{
    public function ejecutar(Consulta $consulta): void
    {
        ConsultaSesion::where('consulta_id', $consulta->id)->delete();
    }
}
