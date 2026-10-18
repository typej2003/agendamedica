<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\Dieta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Quitar una dieta del catálogo del médico** (WEB-12.4b). Una dieta **se copia** al texto de la consulta al elegirla (`dieta_paciente` no guarda la
 * referencia a su fila), así que **siempre se puede quitar** y lo ya escrito no cambia. Sólo toca filas del médico: la de otro consultorio no se encuentra.
 */
final class QuitarDietaDelCatalogo
{
    use EscribeConRastro;

    /**
     * @return string  el título de la dieta quitada
     *
     * @throws InvalidArgumentException si no es del médico
     */
    public function ejecutar(string $regMedico, int $id, ?CarbonInterface $cuando = null, ?string $origen = 'web'): string
    {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $id, $cuando, $origen) {
            $fila = Dieta::where('reg_medico', $regMedico)->where('id', $id)->lockForUpdate()->first();

            if ($fila === null) {
                throw new InvalidArgumentException('Esa dieta no está en tu catálogo.');
            }

            $nombre = trim((string) $fila->dietas) !== '' ? trim((string) $fila->dietas) : 'dieta ' . trim((string) $fila->cod_dieta);
            $this->borrarFila($fila, 'dietas', $cuando, $origen);

            return $nombre;
        });
    }
}
