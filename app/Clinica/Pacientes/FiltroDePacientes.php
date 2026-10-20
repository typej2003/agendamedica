<?php

namespace App\Clinica\Pacientes;

/**
 * El filtro **"Sin historia"** del listado de pacientes: qué es "tener historia" cuando el dato sale del
 * pivote `medico_pacientes`, y cómo se escribe esa condición de una sola forma.
 *
 * Existe por una razón concreta y medida: la ficha y la búsqueda (WEB-3.1) leen el número con
 * `CamposDePaciente::numeroDeHistoria()`, que trata **`null` y `''` como "sin historia"** —el legado deja
 * los dos, y el `!== null` de antes daba por buena una historia vacía—. Si el listado filtrara con
 * `whereNull('numhistoria')` nada más, la pantalla diría "Sin historia" en la fila y el filtro "Sin
 * historia" **no la traería**: la misma palabra significando dos cosas. Acá la condición SQL y la
 * limpieza en PHP viven pegadas, sobre la misma columna.
 *
 * Es el patrón que la guía de la web ya usa para el acento de la búsqueda
 * (`App\Clinica\Pacientes\BusquedaDePacientes`, trampa 13 de `AGENTS.md`): cuando la comparación tiene
 * que ser la misma en SQLite (desarrollo y tests) y en MySQL (producción), la condición se escribe con
 * funciones que existen en los dos motores. `TRIM` y `COALESCE` existen en ambos, así que también se
 * descartan los espacios —`CamposDePaciente::texto()` recorta, y `'   '` no es un número de historia en
 * ninguna de las dos puntas—.
 *
 * La condición se usa con **`whereRaw`**: `where(new Expression(...))` de Laravel 8 la mete en los
 * *bindings* y la comparación termina con un valor de más —medido: el filtro devolvía cero filas
 * mientras la tabla mostraba "Sin historia"—.
 */
final class FiltroDePacientes
{
    /** Todos los pacientes del consultorio: el listado de siempre. */
    public const TODOS = 'todos';

    /** Sólo los que **no** tienen número en el pivote: la lista que la secretaría necesita para llenarlas. */
    public const SIN_HISTORIA = 'sin-historia';

    /** Sólo los que ya lo tienen. */
    public const CON_HISTORIA = 'con-historia';

    /**
     * Los valores que acepta el `?historia=` del listado. El controlador valida contra esto en vez de
     * aceptar lo que venga, así que un valor raro cae en `TODOS` en vez de devolver una lista mezclada.
     *
     * @return array<int,string>
     */
    public static function valores(): array
    {
        return [self::TODOS, self::SIN_HISTORIA, self::CON_HISTORIA];
    }

    /** El valor recibido, si es uno de los válidos; `TODOS` en cualquier otro caso. */
    public static function normalizar(?string $valor): string
    {
        return in_array($valor, self::valores(), true) ? $valor : self::TODOS;
    }

    public static function esSinHistoria(string $filtro): bool
    {
        return $filtro === self::SIN_HISTORIA;
    }

    public static function esConHistoria(string $filtro): bool
    {
        return $filtro === self::CON_HISTORIA;
    }

    /**
     * La condición de "no tiene número de historia", para el `whereRaw` del listado y para los contadores
     * del segmentado. Es **la misma** en los dos usos: si el contador y el filtro no miraran lo mismo, el
     * segmentado ofrecería "Sin historia (2)" y traería una fila.
     *
     * La columna se pasa porque desde la Fase 2 del cambio de `reg_medico` el listado lee el número del
     * **vínculo del contexto**, que entra como `LEFT JOIN medico_pacientes as vinculo` (el pivote se
     * consulta dos veces: una para saber qué pacientes ve el usuario y otra para el número del
     * consultorio en el que se está trabajando). El nombre por defecto es el de siempre, así que las
     * pantallas de un solo consultorio no cambian.
     */
    public static function sqlSinHistoria(string $columna = 'medico_pacientes.numhistoria'): string
    {
        return "TRIM(COALESCE({$columna}, '')) = ''";
    }

    /** La condición complementaria, con la misma vara. */
    public static function sqlConHistoria(string $columna = 'medico_pacientes.numhistoria'): string
    {
        return "TRIM(COALESCE({$columna}, '')) <> ''";
    }
}
