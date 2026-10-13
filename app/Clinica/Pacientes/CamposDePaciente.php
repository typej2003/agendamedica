<?php

namespace App\Clinica\Pacientes;

/**
 * Los campos de `pacientes` tal como los deja el legado, ya limpios para la pantalla.
 *
 * En la base conviven `null`, cadenas vacías, espacios de más y marcadores con significado propio: la
 * cédula `S.C.` es la opción "sin cédula" del alta del legado (Docs/Wiki/04-historia-clinica.md) y
 * `medico_pacientes.numhistoria` es **texto** (puede venir `''`, con espacios o con ceros a la
 * izquierda). Leerlos a ciegas tiene dos consecuencias que este proyecto ya vio: un `''` que pasa el
 * `!== null` y hace creer que el paciente tiene historia, y una `S.C.` que la ficha muestra como si
 * fuera una cédula. Se limpian **una sola vez, acá**, y las consultas y los ViewModels usan esto.
 *
 * La regla de la guía de UI es mostrar `—` cuando el dato no está: para eso, "no está" incluye estos
 * marcadores, no solo el `null`.
 */
final class CamposDePaciente
{
    /** Marcadores de "sin cédula" de la fuente. El manual dice `S.C.`; se toleran las variantes escritas. */
    private const SIN_CEDULA = ['S.C.', 'S.C', 'SC', 'S/C', 'SIN CEDULA', 'SIN CÉDULA'];

    /** Texto del legado sin espacios alrededor, o `null` cuando no hay nada que mostrar. */
    public static function texto($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * El número de historia como texto (conserva los ceros a la izquierda con los que el legado numera:
     * `00001`), o `null` si el vínculo todavía no tiene número.
     */
    public static function numeroDeHistoria($valor): ?string
    {
        return self::texto($valor);
    }

    /**
     * El número como entero, para cruzar con `consultas.numhistoria`, que sí es un entero.
     *
     * `null` si no hay número **o si no es numérico**: castear a ciegas convertiría una basura en `0` y
     * la consulta traería las consultas de otro paciente (el de la historia `0`).
     */
    public static function historiaNumerica($valor): ?int
    {
        $texto = self::numeroDeHistoria($valor);

        return $texto !== null && is_numeric($texto) ? (int) $texto : null;
    }

    /** La cédula, o `null` cuando el legado guardó su marcador de "sin cédula" en vez de dejarla vacía. */
    public static function cedula($valor): ?string
    {
        $texto = self::texto($valor);

        if ($texto === null) {
            return null;
        }

        return self::esSinCedula($texto) ? null : $texto;
    }

    /**
     * ¿El texto es el marcador de "sin cédula"? Es la definición que consulta
     * `App\Actions\Pacientes\IdentidadDePaciente` para no enlazar dos pacientes distintos por el
     * marcador (el vacío no lo mira: eso es "no hay cédula", no un marcador escrito).
     */
    public static function esSinCedula($valor): bool
    {
        return in_array(mb_strtoupper(trim((string) $valor)), self::SIN_CEDULA, true);
    }

    /**
     * El nombre como se muestra en toda la pantalla: `Apellidos, Nombres`.
     *
     * Hay fichas del legado sin nombre ni apellido (una de ellas está en la base de desarrollo): sin
     * esto la ficha quedaría con el título en blanco y el avatar con un `?`.
     */
    public static function nombre($apellidos, $nombres): string
    {
        $nombre = trim(trim((string) $apellidos) . ', ' . trim((string) $nombres), ', ');

        return $nombre !== '' ? $nombre : 'Paciente sin nombre';
    }
}
