<?php

namespace App\Clinica\Catalogos\Iniciales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **A qué especialidades aplica una fila del catálogo inicial** (WEB-12.9): el puente `catalogo_inicial_especialidad`. Una fila **sin marcas es general** (la recibe
 * cualquier médico); con marcas, sólo los médicos de esas especialidades. Es el único lugar que lee y escribe el puente: la copia al médico
 * (`CopiarCatalogoInicial`), el ABM del administrador y la siembra (`SembrarCatalogosIniciales`) pasan por acá.
 */
final class EspecialidadesDelCatalogoInicial
{
    private const TABLA = 'catalogo_inicial_especialidad';

    /**
     * @param  array<int,int>  $filas  ids de filas iniciales del catálogo
     * @return array<int,array<int,int>>  `fila_id => [specialty_id…]` (las filas generales no aparecen)
     */
    public static function de(string $catalogo, array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $marcas = [];
        foreach (array_chunk(array_values(array_unique($filas)), 500) as $lote) {
            foreach (DB::table(self::TABLA)->where('catalogo', $catalogo)->whereIn('fila_id', $lote)->get(['fila_id', 'specialty_id']) as $m) {
                $marcas[(int) $m->fila_id][] = (int) $m->specialty_id;
            }
        }

        return $marcas;
    }

    /**
     * Las filas que le tocan a un médico de estas especialidades: las generales y las marcadas con alguna de ellas.
     *
     * @param  Collection<int,object>  $filas  filas iniciales (con `id`)
     * @param  array<int,int>  $especialidades
     * @return Collection<int,object>
     */
    public static function filtrar(string $catalogo, Collection $filas, array $especialidades): Collection
    {
        $marcas = self::de($catalogo, $filas->pluck('id')->map(fn ($id) => (int) $id)->all());
        $especialidades = array_map('intval', $especialidades);

        return $filas->filter(fn ($fila) => ! isset($marcas[(int) $fila->id]) || array_intersect($marcas[(int) $fila->id], $especialidades) !== [])->values();
    }

    /**
     * Deja la fila con **exactamente** estas especialidades (`[]` = general).
     *
     * @param  array<int,int|string>  $especialidades
     */
    public static function asignar(string $catalogo, int $fila, array $especialidades): void
    {
        $ids = collect($especialidades)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        DB::table(self::TABLA)->where('catalogo', $catalogo)->where('fila_id', $fila)->whereNotIn('specialty_id', $ids->all())->delete();
        self::agregar($catalogo, $fila, $ids->all());
    }

    /**
     * Suma estas especialidades a las que la fila ya tiene (no quita ninguna).
     *
     * @param  array<int,int>  $especialidades
     */
    public static function agregar(string $catalogo, int $fila, array $especialidades): int
    {
        $ya = DB::table(self::TABLA)->where('catalogo', $catalogo)->where('fila_id', $fila)->pluck('specialty_id')->map(fn ($id) => (int) $id)->all();
        $nuevas = array_values(array_diff(array_unique(array_map('intval', $especialidades)), $ya));
        $ahora = now();

        if ($nuevas !== []) {
            DB::table(self::TABLA)->insert(array_map(fn ($id) => [
                'catalogo' => $catalogo, 'fila_id' => $fila, 'specialty_id' => $id, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $nuevas));
        }

        return count($nuevas);
    }

    public static function quitarFila(string $catalogo, int $fila): void
    {
        DB::table(self::TABLA)->where('catalogo', $catalogo)->where('fila_id', $fila)->delete();
    }

    /**
     * Restringe una consulta de filas iniciales a una especialidad (`general` = las que no tienen marca).
     *
     * @param  \Illuminate\Database\Query\Builder  $consulta  sobre la tabla del catálogo
     */
    public static function restringir($consulta, string $catalogo, string $tabla, $especialidad): void
    {
        $marcas = fn ($q) => $q->select(DB::raw(1))->from(self::TABLA)->where('catalogo', $catalogo)->whereColumn('fila_id', $tabla . '.id');

        if ($especialidad === 'general') {
            $consulta->whereNotExists($marcas);
        } elseif ((int) $especialidad > 0) {
            $consulta->whereExists(fn ($q) => $marcas($q)->where('specialty_id', (int) $especialidad));
        }
    }
}
