<?php

namespace App\Clinica\Consulta\Traumatologia;

use App\Models\Consulta;
use App\Models\Implante;

/**
 * **La lectura de la solicitud de implante** (Traumatología, T3): la fila de `implante` de la consulta y sus valores por
 * clave del `MapaDelImplante`. La clave del legado es `historia` + `consulta`.
 */
final class DatosDelImplante
{
    public function fila(Consulta $consulta): ?Implante
    {
        return Implante::where('reg_medico', $consulta->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
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

        foreach (MapaDelImplante::campos() as $campo) {
            $valor = $fila->{$campo['columna']};

            if ($valor instanceof \DateTimeInterface) {
                $valor = $valor->format('Y-m-d');
            }

            $valores[$campo['clave']] = $valor === null ? '' : (string) $valor;
        }

        return $valores;
    }

    /** ¿La consulta tiene algo cargado en la solicitud? */
    public function tieneDatos(Consulta $consulta): bool
    {
        $fila = $this->fila($consulta);

        return $fila !== null
            && collect(MapaDelImplante::columnas())->contains(function (string $columna) use ($fila) {
                $valor = $fila->{$columna};

                if ($valor instanceof \DateTimeInterface) {
                    return true;
                }

                return trim((string) $valor) !== '';
            });
    }
}
