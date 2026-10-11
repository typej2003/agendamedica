<?php

namespace App\Actions\Consulta;

/**
 * **Los medicamentos de un récipe como se escriben**: la regla de normalización que comparten
 * `GuardarRecipe` (el récipe del paciente) y `GuardarRecipeDeLaPareja` (WEB-5.1b), para que las dos
 * escriban la misma forma de fila (PLAN-WEB.md, R3: ninguna regla de negocio se escribe dos veces).
 *
 * Sin estado ni base de datos.
 */
final class ItemsDeRecipe
{
    /**
     * Los medicamentos como se escriben: el código recortado, la cantidad entera o `null`, las
     * indicaciones sin espacios de más (y `''` como `null`) y **un medicamento una sola vez** (la clave del
     * escritorio es `(nrohistoria, nroconsulta, codemedicina)`). Lo que no trae código se descarta.
     *
     * @return array<int,array{codemedicina:string, cantidad:?int, indicaciones:?string, descripcion:?string, nombre:string}>
     */
    public static function normalizar(array $items): array
    {
        $limpios = [];
        $vistos = [];

        foreach (array_values($items) as $item) {
            $codigo = is_array($item) ? trim((string) ($item['codemedicina'] ?? '')) : '';

            if ($codigo === '' || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;

            $descripcion = self::texto($item['descripcion'] ?? null);

            $limpios[] = [
                'codemedicina' => $codigo,
                'cantidad' => self::entero($item['cantidad'] ?? null),
                'indicaciones' => self::texto($item['indicaciones'] ?? null),
                'descripcion' => $descripcion === null ? null : mb_substr($descripcion, 0, 200),
                'nombre' => self::texto($item['nombre'] ?? null) ?? $descripcion ?? $codigo,
            ];
        }

        return $limpios;
    }

    public static function entero($valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }

    /** `''` y `null` son el mismo estado: sin texto. */
    public static function texto($valor): ?string
    {
        if ($valor === null || ! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
