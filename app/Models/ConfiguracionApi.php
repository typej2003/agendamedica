<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La **configuración interna del servidor**, en clave/valor (`configuracion_api`).
 *
 * Es para lo que el servidor necesita recordar entre despliegues y no es un dato clínico ni viaja por el
 * sync: hoy, la **versión de datos** de `php artisan datos:actualizar` (clave `version_datos`). No tiene
 * caché: son pocas filas y se leen cuando corre un comando o se abre una pantalla de configuración.
 *
 * ⚠️ Los valores se guardan en **texto claro**. Si algún día hay que guardar un secreto, va cifrado
 * aparte, no en esta tabla.
 */
class ConfiguracionApi extends Model
{
    protected $table = 'configuracion_api';

    protected $fillable = ['clave', 'valor'];

    /** El valor de una clave, o `$defecto` si la clave no está. */
    public static function valor(string $clave, $defecto = null)
    {
        return static::query()->where('clave', $clave)->value('valor') ?? $defecto;
    }

    /** El valor de una clave como entero; si falta (o está vacío) devuelve `$defecto`. */
    public static function entero(string $clave, int $defecto = 0): int
    {
        $valor = static::valor($clave);

        return $valor === null || $valor === '' ? $defecto : (int) $valor;
    }

    /**
     * Crea o actualiza la fila de una clave (upsert por `clave`) y le mueve `updated_at`.
     *
     * El `touch()` es a propósito: `updateOrCreate()` no escribe si el valor no cambió, y acá interesa
     * dejar anotado cuándo se fijó por última vez.
     */
    public static function fijar(string $clave, $valor): self
    {
        $fila = static::query()->updateOrCreate(
            ['clave' => $clave],
            ['valor' => $valor === null ? null : (string) $valor]
        );

        $fila->touch();

        return $fila;
    }
}
