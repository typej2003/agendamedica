<?php

namespace App\Casts;

use App\Support\RestriccionesPlan;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Columna JSON de restricciones <-> [RestriccionesPlan]. Una columna vacía (`null`) o incompleta se lee con
 * los valores por defecto, así las filas que no tienen una restricción recién agregada siguen funcionando.
 */
class RestriccionesPlanCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): RestriccionesPlan
    {
        return RestriccionesPlan::desde($value);
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        return json_encode(RestriccionesPlan::desde($value)->toArray());
    }
}
