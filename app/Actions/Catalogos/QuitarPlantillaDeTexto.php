<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\FormatoPrint;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Quitar una plantilla de texto** (WEB-8.4). Una plantilla **se copia** al campo de la consulta al elegirla —no queda ninguna
 * referencia viva a su fila—, así que **siempre se puede quitar**: no hay "en uso" que comprobar y lo ya escrito no cambia.
 *
 * Se borra bajo candado, dentro de una transacción, y deja un `deleted` en `sync_changes` (`source = web`). Desde la ola §11 de
 * PLAN-MOVIL.md el móvil **baja** `formato_print`, así que ese `deleted` ya no es sólo el rastro: es lo que hace que el teléfono
 * deje de ofrecer la plantilla que se quitó acá.
 */
final class QuitarPlantillaDeTexto
{
    use EscribeConRastro;

    public function ejecutar(FormatoPrint $fila, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeBaja
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $cuando, $origen) {
            $bloqueada = FormatoPrint::whereKey($fila->id)->lockForUpdate()->firstOrFail();
            $nombre = trim((string) $bloqueada->titulo) !== '' ? trim((string) $bloqueada->titulo) : 'plantilla ' . trim((string) $bloqueada->codigo);

            $this->borrarFila($bloqueada, 'formato_print', $cuando, $origen);

            return ResultadoDeBaja::quitado($nombre);
        });
    }
}
