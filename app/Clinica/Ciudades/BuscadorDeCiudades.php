<?php

namespace App\Clinica\Ciudades;

use App\Models\City;
use Illuminate\Support\Str;

/**
 * Búsqueda de **ciudades** para el selector con búsqueda (`<x-ui.selector-buscable>`).
 *
 * El catálogo tiene cientos de filas (498 sembradas), demasiadas para un select: el selector pide al servidor.
 * La coincidencia es **sin tildes ni mayúsculas y en cualquier parte** del nombre o del estado; se hace en PHP
 * porque la comparación sin tildes no es portable entre SQLite (pruebas) y MySQL (producción), y con unos cientos
 * de filas es barato. Orden: exacta, empieza por el texto, contiene en el nombre, contiene en el estado.
 *
 * El resultado es el formato genérico del selector: `{value, label, detail}`. `value` es el id o, con
 * `$valor = 'nombre'`, el nombre (el registro de médicos y el catálogo de referidos guardan el texto).
 */
class BuscadorDeCiudades
{
    public const LIMITE_MAXIMO = 50;

    /**
     * @param  'id'|'nombre'  $valor
     * @return array<int,array{value:int|string,label:string,detail:string}>
     */
    public function buscar(string $texto, int $limite = 20, string $valor = 'id'): array
    {
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));
        $aguja = self::normalizar($texto);
        if ($aguja === '') {
            return [];
        }

        $encontradas = [];
        foreach ($this->todas() as $fila) {
            $nombre = self::normalizar($fila->name);
            $estado = self::normalizar($fila->estado ?? '');

            if ($nombre === $aguja) {
                $rango = 0;
            } elseif (str_starts_with($nombre, $aguja)) {
                $rango = 1;
            } elseif (str_contains($nombre, $aguja)) {
                $rango = 2;
            } elseif (str_contains($nombre . ' ' . $estado, $aguja)) {
                $rango = 3;
            } else {
                continue;
            }

            $encontradas[] = [$rango, $nombre, $fila];
        }

        usort($encontradas, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($par) => [
            'value'  => $valor === 'nombre' ? (string) $par[2]->name : (int) $par[2]->id,
            'label'  => (string) $par[2]->name,
            'detail' => (string) ($par[2]->estado ?? ''),
        ], array_slice($encontradas, 0, $limite));
    }

    /** La etiqueta con la que se muestra una ciudad ya elegida («Maracaibo — Zulia»). */
    public static function etiqueta(City $ciudad): string
    {
        $estado = $ciudad->state->name ?? '';

        return $estado === '' ? (string) $ciudad->name : $ciudad->name . ' — ' . $estado;
    }

    /** Minúsculas, sin tildes y con los espacios colapsados: la forma en que se comparan los nombres. */
    public static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::ascii(mb_strtolower($texto))));
    }

    private function todas()
    {
        return City::query()
            ->join('estados', 'estados.id', '=', 'cities.state_id')
            ->get(['cities.id', 'cities.name', 'estados.name as estado']);
    }
}
