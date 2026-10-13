<?php

namespace App\Clinica\Embarazo;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Las gestas de una historia** (WEB-6.6, la pestaña *Prenatal* del control de embarazo): lee
 * `pre_natal_observaciones`, que es donde el escritorio guarda cada embarazo.
 *
 * La clave del DataWindow de la pestaña es **`historia` + `gesta_clave`** (`d_pre_natal_observa.srd`, las
 * dos columnas con `key=yes`): una historia puede tener **varias gestas** —el botón *Nueva Gesta* agrega
 * una— y la de mayor número es la que el médico está siguiendo. `gesta_clave` es **nullable** en el
 * esquema y en los datos viejos viene vacía: una fila sin número es la gesta **1**, así que se ordena y se
 * cuenta con `COALESCE(gesta_clave, 1)` (la misma vara para leer y para numerar la próxima).
 *
 * **No se llavea por la consulta**: el control prenatal es del **embarazo**, no de la consulta, así que la
 * lectura va por historia y el `reg_medico` del contexto. Sin HTTP y sin escribir nada: la Action es la que
 * guarda.
 */
final class GestasDeLaHistoria
{
    /** La tabla del control prenatal (la de la pestaña *Prenatal*). */
    public const TABLA = 'pre_natal_observaciones';

    /**
     * Las gestas de la historia, de la primera a la última.
     *
     * @return array<int,Gesta>
     */
    public function de(string $regMedico, int $numhistoria, ?CarbonInterface $hoy = null): array
    {
        return $this->consulta($regMedico, $numhistoria)
            ->get()
            ->map(fn ($fila) => new Gesta((array) $fila, (int) $fila->id, $hoy))
            ->all();
    }

    /** La gesta en curso: la de mayor número (`gesta_clave`), o `null` si la historia no tiene ninguna. */
    public function actual(string $regMedico, int $numhistoria, ?CarbonInterface $hoy = null): ?Gesta
    {
        // `orderByDesc(DB::raw(…))` no aplica la dirección (medido: devolvía la primera fila), así que el
        // `DESC` va dentro del `orderByRaw`, como en el `max()` de `siguienteClave()`.
        $fila = $this->consulta($regMedico, $numhistoria)->reorder()->orderByRaw('COALESCE(gesta_clave, 1) DESC')->first();

        return $fila === null ? null : new Gesta((array) $fila, (int) $fila->id, $hoy);
    }

    /**
     * El número que le toca a la próxima gesta: el mayor de la historia más uno, o **1** si es la primera.
     *
     * Es el `gesta_clave` que el escritorio asigna al apretar *Nueva Gesta*; la web hace la misma cuenta
     * (la última de esa historia + 1), como `CrearConsulta` con el `nroconsulta`.
     */
    public function siguienteClave(string $regMedico, int $numhistoria): int
    {
        $maximo = DB::table(self::TABLA)
            ->where('reg_medico', $regMedico)
            ->where('historia', $numhistoria)
            ->max(DB::raw('COALESCE(gesta_clave, 1)'));

        return $maximo === null ? 1 : (int) $maximo + 1;
    }

    /** La fila de una gesta, o `null`. */
    public function fila(string $regMedico, int $numhistoria, int $clave): ?object
    {
        return DB::table(self::TABLA)
            ->where('reg_medico', $regMedico)
            ->where('historia', $numhistoria)
            ->whereRaw('COALESCE(gesta_clave, 1) = ?', [$clave])
            ->first();
    }

    /** La consulta base, ordenada como se muestra: por número de gesta y, a igual número, por `id`. */
    private function consulta(string $regMedico, int $numhistoria)
    {
        return DB::table(self::TABLA)
            ->where('reg_medico', $regMedico)
            ->where('historia', $numhistoria)
            ->orderByRaw('COALESCE(gesta_clave, 1)')
            ->orderBy('id');
    }
}
