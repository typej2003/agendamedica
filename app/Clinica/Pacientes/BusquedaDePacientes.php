<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Facades\DB;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Laravel\PecleSearchManager;
use Throwable;

/**
 * La búsqueda de pacientes del listado, del buscador del alta de cita y de las sugerencias.
 *
 * **Busca por tokens, no con `LIKE` sobre `pacientes`.** Cada palabra que se escribe tiene que ser el
 * comienzo de una palabra del índice (`search_tokens`: nombres y apellidos del paciente) o coincidir con la
 * cédula (por prefijo) o el n.º de historia (por contiene) del consultorio. Así el costo no crece con la
 * tabla —con millones de pacientes un `LIKE '%texto%'` es un full scan— y el corrector de errores de tecleo
 * (PecleSearch) ayuda a que una palabra escrita mal llegue a la que existe. La cédula y la historia no están
 * en el índice porque son números y el consultorio ya acota las filas: se buscan sobre el pivote.
 *
 * **El id de `pacientes` es numérico y el índice lo sabe.** La fuente de `config/pecle_search.php` declara
 * `numeric_index`, el índice vive en `numeric_search_tokens` y la búsqueda compara entero con entero: el
 * motor usa la PK (medido con 200.000 pacientes: 1 ms contra 15 ms con la tabla de texto). Si un índice no
 * usa la tabla numérica, el paquete convierte la llave a texto; sin esa conversión el motor no usa el índice
 * y la consulta se vuelve un producto cartesiano (15 s en SQLite, >8 s en MariaDB).
 *
 * **El `LIKE` queda de respaldo** mientras el índice no esté construido (una instalación nueva, antes
 * del `pecle-search:rebuild`): funciona, pero recorre la tabla. En ese camino se mantiene la búsqueda
 * por cualquier parte de la palabra y la normalización de acentos según el motor.
 *
 * ⚠️ Las dos ramas suponen que la consulta exterior es `pacientes` (sin alias): el `CAST` y las
 * columnas se escriben con ese nombre.
 */
final class BusquedaDePacientes
{
    /** Más palabras que esto no acotan nada (y multiplican los `EXISTS` de la consulta). */
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
     * Las palabras de la búsqueda, sin repetidas y acotadas. Es del camino de respaldo (`LIKE`); el de
     * tokens usa el normalizador del paquete, que además corta los símbolos.
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

    /**
     * La expresión SQL que deja una columna comparable sin acentos ni mayúsculas, para el respaldo.
     *
     * **En MySQL no hace falta la cadena de `REPLACE`**: las tablas del legado usan una colación `_ci`, que
     * compara sin acentos ni mayúsculas, y encadenar trece `REPLACE` por columna y por palabra multiplica el
     * costo sin cambiar el resultado (medido con 6.000 pacientes: 55 ms → 1,6 ms en SQLite; 6,8 → 2,5 ms en
     * MariaDB). SQLite —desarrollo y tests— sólo pliega mayúsculas ASCII, así que ahí la normalización
     * explícita sigue siendo la que hace que `Jaquez` encuentre a `Jáquez`.
     *
     * `$motor` se puede pasar para probar las dos ramas sin una conexión MySQL; por defecto, el de la
     * conexión.
     */
    public static function columnaNormalizada(string $columna, ?string $motor = null): string
    {
        $motor ??= DB::connection()->getDriverName();

        if ($motor === 'mysql') {
            return $columna;
        }

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
     * Agrega la búsqueda a la consulta: un `where` por palabra (se exigen todas). El grupo va anidado para
     * que el `or` de cada palabra no se escape del filtro por `reg_medico`.
     *
     * `$columnaHistoria` es la columna del número del consultorio: el listado lee el pivote del **contexto**
     * con el alias `vinculo` (`LEFT JOIN medico_pacientes as vinculo`).
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $consulta
     */
    public static function aplicar($consulta, string $buscar, string $columnaHistoria = 'medico_pacientes.numhistoria')
    {
        if (self::elIndiceEstaConstruido()) {
            return self::porTokens($consulta, $buscar, $columnaHistoria);
        }

        return self::porLike($consulta, $buscar, $columnaHistoria);
    }

    /**
     * La búsqueda de verdad: cada palabra contra la tabla de tokens del índice (comienzo de palabra
     * indexada) o contra el n.º de historia del consultorio. La usan el listado, sus conteos y las
     * sugerencias del alta.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $consulta
     */
    public static function porTokens($consulta, string $buscar, string $columnaHistoria): mixed
    {
        // El mismo normalizador con el que se construyó el índice (minúsculas, sin acentos, [a-z0-9]):
        // si acá se normalizara distinto, el token no coincidiría. `searchTokens` ya descarta los
        // comodines de `LIKE` y los signos, así que el prefijo es seguro.
        $tokens = array_slice(app(Normalizer::class)->searchTokens($buscar), 0, self::MAX_PALABRAS);

        if ($tokens === []) {
            return $consulta->whereRaw('0 = 1');
        }

        // La tabla y la comparación las decide el paquete según `numeric_index` de la fuente: con el id
        // numérico (el de `pacientes`) el índice vive en `numeric_search_tokens` y se compara entero con
        // entero; con la tabla de texto y un id numérico hay que convertir la llave o el motor no usa la PK.
        $buscador = app(PecleSearchManager::class);
        $indice = CorrectorDeLaBusqueda::INDICE;
        $tabla = $buscador->tokensTable($indice);
        $comparacion = $buscador->numericIndex($indice)
            ? 'pacientes.id'
            : ($consulta->getConnection()->getDriverName() === 'mysql'
                ? 'CAST(pacientes.id AS CHAR)'
                : 'CAST(pacientes.id AS TEXT)');

        foreach ($tokens as $token) {
            $consulta->where(function ($grupo) use ($token, $columnaHistoria, $tabla, $comparacion) {
                $grupo->whereExists(function ($sub) use ($token, $tabla, $comparacion) {
                    $sub->selectRaw('1')
                        ->from($tabla)
                        ->where($tabla.'.index_name', CorrectorDeLaBusqueda::INDICE)
                        ->whereRaw("{$tabla}.entity_id = {$comparacion}")
                        ->where($tabla.'.token', 'like', $token . '%');
                });

                // El n.º de historia y la cédula sólo pueden ser la palabra buscada si tiene dígitos:
                // sumarle un `or` con el pivote a una palabra de letras impide que el motor use el
                // semi-join del índice (medido: 16 → 34 ms por consulta con 200.000 pacientes). La cédula
                // se busca además por prefijo sobre las filas del consultorio: cubre un índice que no la
                // tenga y cuesta poco, porque el consultorio ya es la tabla que manda en el plan. Una
                // historia no numérica del legado (`S/H`) no se encuentra por acá; el `LIKE` de respaldo
                // sigue cubriéndola.
                if (preg_match('/[0-9]/', $token) === 1) {
                    $grupo->orWhereRaw("pacientes.cedula like ? escape '!'", [$token . '%']);

                    foreach (self::formasDeNumero($token) as $forma) {
                        $grupo->orWhereRaw("{$columnaHistoria} like ? escape '!'", ['%' . self::escaparLike($forma) . '%']);
                    }
                }
            });
        }

        return $consulta;
    }

    /**
     * El respaldo mientras el índice no está construido: `LIKE` sobre las columnas del paciente, con la
     * normalización de acentos según el motor. Encuentra por cualquier parte de la palabra.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $consulta
     */
    private static function porLike($consulta, string $buscar, string $columnaHistoria): mixed
    {
        $motor = $consulta->getConnection()->getDriverName();

        foreach (self::palabras($buscar) as $palabra) {
            $consulta->where(function ($grupo) use ($palabra, $columnaHistoria, $motor) {
                $termino = '%' . self::escaparLike(self::normalizar($palabra)) . '%';

                $grupo->whereRaw(self::columnaNormalizada('pacientes.nombres', $motor) . " like ? escape '!'", [$termino])
                    ->orWhereRaw(self::columnaNormalizada('pacientes.apellidos', $motor) . " like ? escape '!'", [$termino])
                    ->orWhereRaw("pacientes.cedula like ? escape '!'", [$termino]);

                foreach (self::formasDeNumero($palabra) as $forma) {
                    $grupo->orWhereRaw("{$columnaHistoria} like ? escape '!'", ['%' . self::escaparLike($forma) . '%']);
                }
            });
        }

        return $consulta;
    }

    /** ¿Hay índice construido para buscar por tokens? Sin tablas o sin aplicación: no. */
    private static function elIndiceEstaConstruido(): bool
    {
        try {
            return app(PecleSearchManager::class)->isBuilt(CorrectorDeLaBusqueda::INDICE);
        } catch (Throwable) {
            return false;
        }
    }
}
