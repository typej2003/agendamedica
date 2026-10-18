<?php

namespace App\Especialidades;

use App\Models\Specialty;
use Illuminate\Support\Arr;

/**
 * La configuración **de la especialidad del contexto** (`config/especialidades/<archivo>.php`) tal como la
 * escribió su manifiesto, para las decisiones de producto que cambian por especialidad y que no son tablas
 * ni módulos: con qué sexo arranca el alta de un paciente, por ejemplo (O-01, 2026-10-17).
 *
 * `ManifiestoEspecialidad` se queda con lo que valida el marco multi-especialidad (módulos, tablas,
 * ventanas); esto lee el archivo crudo, así que una clave nueva no obliga a tocar el objeto de valor. El
 * archivo se busca por su `specialty_slug` —el de la fila `specialties`— y, si no lo declara, por el nombre
 * del archivo, que es la convención por defecto.
 */
final class ConfiguracionDeLaEspecialidad
{
    /**
     * Todas las claves del manifiesto de esa especialidad, o `[]` si no tiene manifiesto (o no hay contexto).
     *
     * @return array<string,mixed>
     */
    public static function de(?Specialty $especialidad): array
    {
        if ($especialidad === null) {
            return [];
        }

        foreach ((array) config('especialidades', []) as $archivo => $datos) {
            $slug = (string) ($datos['specialty_slug'] ?? $archivo);

            if ($slug === $especialidad->slug) {
                return (array) $datos;
            }
        }

        return [];
    }

    /**
     * Una clave del manifiesto, por su ruta con puntos (`paciente.sexo_por_defecto`), o `$defecto` cuando esa
     * especialidad no la declara. Una especialidad sin manifiesto tampoco la declara.
     *
     * @param  mixed  $defecto
     * @return mixed
     */
    public static function valor(?Specialty $especialidad, string $clave, $defecto = null)
    {
        return Arr::get(self::de($especialidad), $clave, $defecto);
    }
}
