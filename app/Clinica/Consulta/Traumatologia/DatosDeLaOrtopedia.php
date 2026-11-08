<?php

namespace App\Clinica\Consulta\Traumatologia;

use App\Models\Consulta;
use App\Models\Ortopedia;

/**
 * **La lectura de la plantilla ortopédica** (Traumatología, 2026-11-07): la fila de `ortopedia` de la consulta y sus
 * valores por clave del `MapaDeLaOrtopedia`.
 */
final class DatosDeLaOrtopedia
{
    public function fila(Consulta $consulta): ?Ortopedia
    {
        return Ortopedia::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * @return array<string,string> clave del mapa => valor guardado ('' si no tiene)
     */
    public function valores(Consulta $consulta): array
    {
        $fila = $this->fila($consulta);

        if ($fila === null) {
            return [];
        }

        $valores = [];

        foreach (MapaDeLaOrtopedia::campos() as $campo) {
            $valor = $fila->{$campo['columna']};
            $valores[$campo['clave']] = $valor === null ? '' : (string) $valor;
        }

        return $valores;
    }

    /** ¿La consulta tiene algo cargado en la plantilla? */
    public function tieneDatos(Consulta $consulta): bool
    {
        $fila = $this->fila($consulta);

        return $fila !== null
            && collect(MapaDeLaOrtopedia::columnas())->contains(fn (string $columna) => trim((string) $fila->{$columna}) !== '');
    }
}
