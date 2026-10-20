<?php

namespace App\Clinica\Pacientes;

/**
 * La búsqueda de pacientes del listado y del buscador del alta de cita.
 *
 * Dos problemas reales del legado obligan a que no sea un `like "%texto%"` sobre una columna:
 *
 * 1. **El nombre está partido en dos columnas** (`nombres` y `apellidos`). Buscar "Ana Alvarez" contra
 *    cada columna por separado no encuentra nada nunca. Acá se parte lo buscado en palabras y se exige
 *    que **cada palabra** aparezca en alguna de las columnas (nombre, apellido, cédula o historia), sin
 *    importar el orden.
 * 2. **Los acentos y las mayúsculas no se comportan igual en los dos motores.** `LIKE` de SQLite
 *    (desarrollo y tests) sólo pliega mayúsculas ASCII: `Jaquez` **no** encuentra `Jáquez`. MySQL, con
 *    su colación `_ci` (producción), sí. Para que la búsqueda sea la misma en los dos, la comparación
 *    se hace sobre una expresión que saca los acentos de la columna (`REPLACE`, que existe en los dos
 *    motores) y baja todo a minúsculas; la palabra buscada se normaliza igual en PHP. `REPLACE` y
 *    `LOWER` son estándar en SQLite y en MySQL, así que la promesa es la misma en los dos.
 *
 * El número de historia es **texto** en `medico_pacientes` y entero en `consultas`: el legado numera
 * `00001`, AppDDR numera `1`, y las dos formas tienen que encontrar al mismo paciente, así que cada
 * palabra numérica se busca tal cual **y** sin sus ceros a la izquierda.
 */
final class BusquedaDePacientes
{
    /** Más palabras que esto no acotan nada (y multiplican los `like` de la consulta). */
    private const MAX_PALABRAS = 5;

    /** Una palabra más larga que la columna más ancha del paciente no puede ser un nombre real. */
    private const MAX_LARGO = 40;

    /**
     * Los acentos que aparecen en los nombres del legado, con las dos cajas: en la expresión SQL las
     * mayúsculas acentuadas no las baja `LOWER` de SQLite (sólo pliega ASCII), así que se reemplazan
     * explícitamente antes de bajar todo a minúsculas.
     */
    private const ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];

    /**
     * Las palabras de la búsqueda, sin repetidas y acotadas.
     *
     * @return array<int, string>
     */
    public static function palabras(string $buscar): array
    {
        $palabras = preg_split('/\s+/u', trim($buscar), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $palabras = array_map(fn ($palabra) => mb_substr($palabra, 0, self::MAX_LARGO), $palabras);

        return array_slice(array_values(array_unique($palabras)), 0, self::MAX_PALABRAS);
    }

    /** La palabra comparable: minúsculas y sin acentos. Tiene que usar el mismo mapa que la expresión SQL. */
    public static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto, 'UTF-8'), self::ACENTOS);
    }

    /** La expresión SQL que deja una columna comparable sin acentos ni mayúsculas (ver el porqué de la clase). */
    public static function columnaNormalizada(string $columna): string
    {
        $expresion = $columna;

        foreach (self::ACENTOS as $conAcento => $sinAcento) {
            $expresion = "REPLACE({$expresion}, '{$conAcento}', '{$sinAcento}')";
        }

        return "LOWER({$expresion})";
    }

    /**
     * Las formas con las que se busca una palabra en el número de historia: tal cual y sin los ceros a
     * la izquierda (`01001` y `1001` son el mismo paciente). Se conserva la forma original para que una
     * historia no numérica del legado (`S/H`) también se pueda buscar por texto.
     *
     * @return array<int, string>
     */
    public static function formasDeNumero(string $palabra): array
    {
        $formas = [trim($palabra)];

        if (preg_match('/^0+[0-9]+$/', $palabra) === 1) {
            $formas[] = ltrim($palabra, '0');
        }

        return array_values(array_unique(array_filter($formas, fn ($forma) => $forma !== '')));
    }

    /**
     * La palabra lista para ir dentro de un `LIKE`: los comodines (`%`, `_`) y el propio carácter de escape
     * se anteponen con `!`. Sin esto, buscar `%` devolvía todos los pacientes y `An_` encontraba a "Ana":
     * lo que se escribe se busca **como texto**. Se usa `!` (y `ESCAPE '!'` en la consulta) y no la barra
     * invertida porque en SQLite y en MySQL el literal `'\'` no significa lo mismo, y `!` sí.
     */
    public static function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }

    /**
     * Agrega el grupo de búsqueda a la consulta: un `where` por palabra (se exigen todas), y dentro de
     * cada uno un `or` entre las columnas del paciente. El grupo va anidado para que el `or` no se
     * escape del filtro por `reg_medico`.
     *
     * `$columnaHistoria` es la columna del número: desde la Fase 2 del cambio de `reg_medico` el listado
     * lee el pivote del **contexto** con el alias `vinculo` (`LEFT JOIN medico_pacientes as vinculo`), y
     * el nombre pelado ya no existe en esa consulta. El valor por defecto es el de siempre.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $consulta
     */
    public static function aplicar($consulta, string $buscar, string $columnaHistoria = 'medico_pacientes.numhistoria')
    {
        foreach (self::palabras($buscar) as $palabra) {
            $consulta->where(function ($grupo) use ($palabra, $columnaHistoria) {
                $termino = '%' . self::escaparLike(self::normalizar($palabra)) . '%';

                $grupo->whereRaw(self::columnaNormalizada('pacientes.nombres') . " like ? escape '!'", [$termino])
                    ->orWhereRaw(self::columnaNormalizada('pacientes.apellidos') . " like ? escape '!'", [$termino])
                    ->orWhereRaw("pacientes.cedula like ? escape '!'", [$termino]);

                foreach (self::formasDeNumero($palabra) as $forma) {
                    $grupo->orWhereRaw("{$columnaHistoria} like ? escape '!'", ['%' . self::escaparLike($forma) . '%']);
                }
            });
        }

        return $consulta;
    }
}
