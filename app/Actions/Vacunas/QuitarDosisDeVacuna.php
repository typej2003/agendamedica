<?php

namespace App\Actions\Vacunas;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\VacunaPaciente;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * **Quitar de la lista una dosis programada** (O-68/O-69): el «Quitar registro» del escritorio para una fila que **no** se
 * aplicó. Sin esto, una vacuna agregada por error no tendría vuelta atrás desde la web (y el escritorio no la tiene, porque
 * no baja lo que crea la web).
 *
 * **Lo relevado**: `pb_80` de la solapa *vacunas* (`w_consulta_principal.srw:23713-23727`) borra la fila de `dw_34` y la
 * manda con `dw_34.update()`, pero **antes** frena si `ejecutada = 'S'` o si `comentario = 'D'` con «El Registro no puede
 * ser Eliminado». Es la misma regla que se aplica acá: una dosis aplicada **no** se quita desde la lista (se corrige, si
 * hace falta, en su propia tarea: es historia clínica). `comentario = 'D'` es la marca de «descontado del inventario» que
 * pone el escritorio al aplicar (`w_consulta_principal.srw:24098`), así que sólo aparece en filas aplicadas.
 *
 * Deja el `deleted` en `sync_changes` (`source = web`), como las otras dos Actions de la dosis.
 */
final class QuitarDosisDeVacuna
{
    use EscribeConRastro;

    /**
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     * @return object  `fila` (los datos que tenía antes de borrarse)
     *
     * @throws InvalidArgumentException
     */
    public function ejecutar(string $regMedico, int $historia, int $id, ?CarbonInterface $cuando = null, ?string $origen = 'web'): object
    {
        $fila = VacunaPaciente::query()
            ->where('reg_medico', $regMedico)->where('historia', $historia)->whereKey($id)
            ->first();

        if ($fila === null) {
            throw new InvalidArgumentException('Esa vacuna ya no está en la lista del paciente.');
        }

        if (strtoupper((string) $fila->ejecutada) === 'S') {
            throw new InvalidArgumentException('Una dosis aplicada no se quita de la lista.');
        }

        $datos = (object) ['vacuna' => $fila->descripcion, 'dosis' => $fila->dosis];

        $this->borrarFila($fila, 'vacuna_paciente', $this->ahora($cuando), $origen);

        return (object) ['fila' => $datos];
    }
}
