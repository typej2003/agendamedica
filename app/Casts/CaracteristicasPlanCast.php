<?php

namespace App\Casts;

use App\Support\CaracteristicasPlan;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Columna JSON de características <-> [CaracteristicasPlan]. Una columna vacía (`null`) o
 * incompleta se lee con los valores por defecto, así las filas viejas siguen funcionando.
 */
class CaracteristicasPlanCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): CaracteristicasPlan
    {
        return CaracteristicasPlan::desde($value);
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        return json_encode(CaracteristicasPlan::desde($value)->toArray());
    }
}
