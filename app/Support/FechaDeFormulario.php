<?php

namespace App\Support;

/**
 * **Las fechas de un formulario: una sola regla para leerlas y para escribirlas** (O-18 y O-19).
 *
 * En la base, una columna `date` guarda **`AAAA-MM-DD`** y MySQL la rechaza si llega otra cosa
 * (`SQLSTATE[22007] … Incorrect date value: '09/05/2026'`, el error de producción de O-19). En la pantalla,
 * en cambio, el médico **teclea `dd/mm/aaaa`** —la FUR del eco es un campo de texto, como en el escritorio,
 * y su máscara pone las barras—. SQLite acepta casi cualquier cosa en una columna `date`, así que la
 * diferencia no se veía hasta llegar al servidor.
 *
 * Esta clase es el **único lugar** donde esa diferencia se resuelve:
 *
 *  - {@see aBase()} lee lo que llega (`dd/mm/aaaa`, `d/m/aaaa` o ya `AAAA-MM-DD`) y devuelve el
 *    `AAAA-MM-DD` de la columna, o `null` si no es una fecha del calendario (`32/13/2026`, `2026-02-30`)
 *    o si viene vacía.
 *  - {@see normalizar()} hace eso mismo sobre el arreglo de un `POST`, **sin tocar** lo que no puede leer:
 *    así la validación `date_format:Y-m-d` del controlador lo rechaza y el médico ve el mensaje, en vez de
 *    que la fecha se guarde «corregida» a otra cosa.
 *  - {@see paraTeclear()} / {@see comoSeTecleo()} son el camino de vuelta: lo guardado se muestra
 *    `dd/mm/aaaa` y lo que **no** es una fecha se muestra **vacío** (no se inventa).
 *
 * Se usa en los ecos (la FUR tecleada de los dos obstétricos, el Doppler y el cardiograma fetal) y en los
 * documentos (récipe, reposo, informe y las fechas del control prenatal), para que la regla no se escriba
 * una vez por pantalla.
 *
 * La aceptación de `AAAA-MM-DD` no es un adorno: por ese formato llegan las plantillas, el sync y el
 * `<input type="date">` de los otros campos, y todos tienen que seguir funcionando.
 */
final class FechaDeFormulario
{
    /** El formato de la columna `date` de la base: es lo que se guarda. */
    public const FORMATO_BASE = 'Y-m-d';

    /**
     * El formato de la pantalla por defecto. Lo que se teclea va en el orden del dispositivo (WEB-13.5,
     * `Regionalizacion`): `dd/mm/aaaa` o `mm/dd/aaaa`.
     */
    public const FORMATO_PANTALLA = 'd/m/Y';

    /** El campo oculto que la máscara agrega al formulario con el orden en que se tecleó (`dmy`/`mdy`). */
    public const CAMPO_ORDEN = '_fecha_orden';

    /** La regla del formato: lo que se guarda en una columna `date` es `AAAA-MM-DD` y nada más. */
    public const FORMATO = 'date_format:Y-m-d';

    /** La regla completa de una fecha **opcional** (el controlador le pone el mensaje en español). */
    public const REGLA = 'nullable|' . self::FORMATO;

    /**
     * La fecha que se guarda en la columna: `AAAA-MM-DD`, o `null` si viene vacía o no es una fecha.
     *
     * Acepta los dos formatos que el sistema escribe: el de la pantalla (`dd/mm/aaaa`, con uno o dos
     * dígitos en el día y el mes) y el de la base (`AAAA-MM-DD`, el del `<input type="date">`, las
     * plantillas y el sync). El día y el mes se validan contra el calendario: `31/02/2026` no es una fecha.
     */
    public static function aBase($valor, ?string $orden = null): ?string
    {
        if (! is_string($valor) && ! is_int($valor) && ! is_float($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '' || strcasecmp($texto, 'none') === 0) {
            return null;
        }

        // El formato de la base: `AAAA-MM-DD`.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $texto, $partes)) {
            return self::armar((int) $partes[1], (int) $partes[2], (int) $partes[3]);
        }

        // El formato de la pantalla: `dd/mm/aaaa` (y `dd-mm-aaaa`, que es como el legado guardó algunas), o
        // `mm/dd/aaaa` si se tecleó con un dispositivo mes-día.
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $texto, $partes)) {
            return self::ordenDe($orden) === Regionalizacion::MDY
                ? self::armar((int) $partes[3], (int) $partes[1], (int) $partes[2])
                : self::armar((int) $partes[3], (int) $partes[2], (int) $partes[1]);
        }

        return null;
    }

    /** ¿Es una fecha que se puede guardar en una columna `date`? */
    public static function esValida($valor): bool
    {
        return self::aBase($valor) !== null;
    }

    /**
     * Una fecha **guardada**, lista para el campo de la pantalla: `dd/mm/aaaa`, o **vacío** si lo que hay
     * en la columna no es una fecha (un `NULL`, el literal `none` del legado, una fecha imposible o
     * cualquier texto que se haya colado antes de esta regla). No se inventa nada.
     */
    public static function paraTeclear($valor): string
    {
        $base = self::aBase($valor);

        if ($base === null) {
            return '';
        }

        [$anio, $mes, $dia] = explode('-', $base);

        return \Illuminate\Support\Carbon::createFromDate((int) $anio, (int) $mes, (int) $dia)
            ->format(Regionalizacion::formatoActual());
    }

    /**
     * Lo que se **acaba de teclear** y volvió con un error de validación (el `old()` del formulario): se
     * muestra en el formato de la pantalla si es una fecha, y **tal como se escribió** si no lo es, para
     * que el médico lo corrija en vez de encontrarse el campo vacío.
     */
    public static function comoSeTecleo($valor): string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return '';
        }

        return self::esValida($texto) ? self::paraTeclear($texto) : $texto;
    }

    /**
     * Pasa a `AAAA-MM-DD` las columnas de fecha de un `POST`, antes de validarlo.
     *
     * Lo que no se puede leer **queda como vino**: la validación `date_format:Y-m-d` lo rechaza y el
     * mensaje dice cuál es el campo. Convertirlo «a la fuerza» sería inventar una fecha clínica.
     *
     * @param  array<string,mixed>  $datos  los datos del formulario
     * @param  array<int,string>  $columnas  las columnas de fecha que trae ese formulario
     * @return array<string,mixed>
     */
    public static function normalizar(array $datos, array $columnas): array
    {
        // El orden en que se tecleó, si la máscara lo mandó; si no, el del dispositivo de este request.
        $orden = is_string($datos[self::CAMPO_ORDEN] ?? null) ? $datos[self::CAMPO_ORDEN] : null;

        foreach ($columnas as $columna) {
            if (! array_key_exists($columna, $datos)) {
                continue;
            }

            $base = self::aBase($datos[$columna], $orden);

            if ($base !== null) {
                $datos[$columna] = $base;
                continue;
            }

            // Vacía (o el `none` del legado) es «sin dato», como en el resto de los vacíos del escritorio.
            if (self::esVacio($datos[$columna])) {
                $datos[$columna] = null;
            }
        }

        return $datos;
    }

    /**
     * El mensaje de una fecha inválida, en español y con el rótulo de la pantalla (no con el nombre de la
     * columna): dice **cómo se escribe**, no que «el campo no coincide con el formato».
     */
    public static function mensaje(string $etiqueta, bool $tecleada = false): string
    {
        $region = Regionalizacion::actual();

        return '«' . $etiqueta . '» no es una fecha válida. Escríbela '
            . ($tecleada
                ? 'como ' . $region->patronDeTecleo() . ', por ejemplo ' . $region->ejemploDeTecleo() . '.'
                : 'como aaaa-mm-dd, por ejemplo 2026-05-09.');
    }

    /** `dmy`/`mdy`: el que se pidió si es uno de los dos, o el del dispositivo. */
    private static function ordenDe(?string $orden): string
    {
        return in_array($orden, [Regionalizacion::DMY, Regionalizacion::MDY], true)
            ? $orden
            : Regionalizacion::actual()->orden;
    }

    /** `null`, `''` y el literal `none` del legado son el mismo «sin dato». */
    private static function esVacio($valor): bool
    {
        if ($valor === null) {
            return true;
        }

        if (! is_string($valor)) {
            return false;
        }

        $texto = trim($valor);

        return $texto === '' || strcasecmp($texto, 'none') === 0;
    }

    /** Arma `AAAA-MM-DD` validando el calendario (incluido el 29 de febrero de un año bisiesto). */
    private static function armar(int $anio, int $mes, int $dia): ?string
    {
        if (! checkdate($mes, $dia, $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }
}
