<?php

namespace App\Clinica\Consulta;

/**
 * **La imagen que el examen tiene en dos versiones** (O-09): el nombre de cada modo, el esquema de cada variante con su
 * imagen base y la forma de la clave del lienzo. Es la única fuente de esos textos y de esas rutas: la usan la tarjeta
 * (`Vistas\ImagenDelExamenVista`), la lectura (`ImagenDelExamenDeLaConsulta`), la hoja impresa
 * (`Impresion\ImagenesDelExamenParaElInforme`) y el controlador, y así lo que se elige en pantalla es exactamente lo
 * que se imprime.
 *
 * ## Los dos modos (la decisión de O-09, no se re-discute)
 *
 *  - **`esquema`** — *Imagen predeterminada*: el esquema del examen, el que traía A-13 (dos variantes del cuello
 *    uterino, cada una con su base vectorial o la del escritorio).
 *  - **`foto`** — *Videocolposcopio*: la foto real del estudio (la de `imagen_consulta` con la etiqueta del examen), que
 *    se puede **marcar** igual que el esquema.
 *
 * ## La clave del lienzo
 *
 * Cada trazo (`dibujos_del_examen`) cuelga de un **lienzo**, y el lienzo se nombra con una clave:
 * `esquema:<variante>` o `foto:<id de la imagen>`. Esa clave es la que hace única la fila en las dos bases y la que
 * permite que el trazo de una foto se vaya con ella.
 *
 * Sin estado, sin base de datos y sin tocar el disco.
 */
final class ImagenDelExamen
{
    /** La colposcopía: la `tabpage_31` del escritorio y la primera solapa que tuvo la tarjeta (A-13). */
    public const COLPOSCOPIA = 'colposcopia';

    /**
     * La **histeroscopía** (O-10): la `tabpage_49` del escritorio, que era sólo un texto. Tiene la misma tarjeta de dos
     * modos y su esquema es la cavidad uterina con los dos ostium y el canal cervical (`public/img/clinica/histeroscopia.svg`).
     */
    public const HISTEROSCOPIA = 'histeroscopia';

    /** Los exámenes que tienen la tarjeta de dos modos. */
    public const EXAMS = [self::COLPOSCOPIA, self::HISTEROSCOPIA];

    /** Las variantes del esquema de la colposcopía: las dos que traía la imagen del escritorio. */
    public const NULIPARA = 'nulipara';
    public const MULTIPARA = 'multipara';

    /** La variante del esquema de la histeroscopía: la cavidad uterina, que es una sola. */
    public const UTERO = 'utero';

    public const VECTOR = 'vector';
    public const ESCRITORIO = 'escritorio';
    public const BASES = [self::VECTOR, self::ESCRITORIO];

    /** La base que se pinta sin que el médico elija (la vectorial: la nueva y limpia). */
    public const BASE_POR_DEFECTO = self::VECTOR;

    public const MODO_ESQUEMA = 'esquema';
    public const MODO_FOTO = 'foto';
    public const MODOS = [self::MODO_ESQUEMA, self::MODO_FOTO];

    /**
     * Las variantes del esquema de cada examen, en el orden del escritorio.
     *
     * @var array<string,array<int,string>>
     */
    private const VARIANTES = [
        self::COLPOSCOPIA => [self::NULIPARA, self::MULTIPARA],
        self::HISTEROSCOPIA => [self::UTERO],
    ];

    /**
     * El `alt` y la etiqueta impresa de cada variante del esquema.
     *
     * @var array<string,array{titulo:string, descripcion:string}>
     */
    private const VARIANTES_TEXTO = [
        self::NULIPARA => ['titulo' => 'Cuello nulípara', 'descripcion' => 'Orificio transverso'],
        self::MULTIPARA => ['titulo' => 'Cuello multípara', 'descripcion' => 'Orificio redondo'],
        // El esquema que dejó el cliente para la histeroscopía: la cavidad uterina con los dos ostium y el canal cervical.
        self::UTERO => ['titulo' => 'Cavidad uterina', 'descripcion' => 'Con los dos ostium y el canal cervical'],
    ];

    /** La etiqueta de cada base, en el orden en que se ofrecen (la vectorial primero: es la que se ve por defecto). */
    private const BASES_TEXTO = [
        self::VECTOR => 'Vectorial',
        self::ESCRITORIO => 'Del escritorio',
    ];

    /** Cómo se llama la tarjeta de cada examen. */
    private const TARJETAS = [
        self::COLPOSCOPIA => ['titulo' => 'Imagen de la colposcopía', 'icono' => 'bi-brush', 'articulo' => 'la'],
        self::HISTEROSCOPIA => ['titulo' => 'Imagen de la histeroscopía', 'icono' => 'bi-camera', 'articulo' => 'la'],
    ];

    /** ¿El valor es uno de los exámenes con tarjeta? */
    public static function existe(?string $examen): bool
    {
        return $examen !== null && in_array($examen, self::EXAMS, true);
    }

    /**
     * El examen que corresponde a una **solapa del examen** (su clave es la misma: `colposcopia`), o `null` si la solapa
     * no tiene la tarjeta. Es el camino que usa el controlador para saber si tiene que armarla.
     */
    public static function deLaSolapa(?string $solapa): ?string
    {
        return self::existe($solapa) ? $solapa : null;
    }

    /** El rótulo de la tarjeta (`Imagen de la colposcopía`). */
    public static function titulo(string $examen): string
    {
        return self::TARJETAS[$examen]['titulo'] ?? 'Imagen del examen';
    }

    /** El icono de la tarjeta (`bi-brush`). */
    public static function icono(string $examen): string
    {
        return self::TARJETAS[$examen]['icono'] ?? 'bi-brush';
    }

    /** El artículo de la tarjeta (`la`), para las frases de la pantalla. */
    public static function articulo(string $examen): string
    {
        return self::TARJETAS[$examen]['articulo'] ?? 'el';
    }

    /**
     * Las variantes del esquema del examen (la colposcopía tiene dos: nulípara y multípara).
     *
     * @return array<int,string>
     */
    public static function variantes(string $examen): array
    {
        return self::VARIANTES[$examen] ?? [];
    }

    /** ¿La variante es del esquema de ese examen? */
    public static function esVariante(string $examen, ?string $variante): bool
    {
        return $variante !== null && in_array($variante, self::variantes($examen), true);
    }

    public static function tituloDeLaVariante(string $variante): string
    {
        return self::VARIANTES_TEXTO[$variante]['titulo'] ?? 'Esquema';
    }

    public static function descripcionDeLaVariante(string $variante): string
    {
        return self::VARIANTES_TEXTO[$variante]['descripcion'] ?? '';
    }

    /** El rótulo de la variante con su forma del orificio: `Cuello nulípara (orificio transverso)`. */
    public static function etiquetaDeLaVariante(string $variante): string
    {
        $descripcion = self::descripcionDeLaVariante($variante);

        return self::tituloDeLaVariante($variante) . ($descripcion === '' ? '' : ' (' . mb_strtolower($descripcion) . ')');
    }

    /**
     * Las bases que ofrece el esquema (`vector` y `escritorio`), `valor => rótulo`. Un esquema sin base elegible —el de
     * la histeroscopía, O-10— devuelve una lista vacía.
     *
     * @return array<string,string>
     */
    public static function bases(string $examen): array
    {
        return $examen === self::COLPOSCOPIA ? self::BASES_TEXTO : [];
    }

    /** La base normalizada: la pedida si existe y, si no, la de por defecto. */
    public static function base(string $examen, ?string $base): string
    {
        return in_array($base, array_keys(self::bases($examen)), true) ? $base : self::BASE_POR_DEFECTO;
    }

    /** El rótulo de una base (`Vectorial`, `Del escritorio`). */
    public static function nombreDeLaBase(string $base): string
    {
        return self::BASES_TEXTO[$base] ?? self::BASES_TEXTO[self::BASE_POR_DEFECTO];
    }

    /**
     * La ruta **relativa a `public/`** de la imagen de una variante del esquema: la vectorial es un SVG y la del
     * escritorio, el PNG escalado. `null` si esa variante no tiene esquema.
     */
    public static function ruta(string $examen, string $variante, string $base): ?string
    {
        if ($examen === self::COLPOSCOPIA) {
            return 'img/clinica/colposcopia-' . $variante . '.'
                . (self::base($examen, $base) === self::ESCRITORIO ? 'png' : 'svg');
        }

        // La histeroscopía tiene **un solo** esquema y no elige base: el SVG que dejó el cliente (O-10).
        if ($examen === self::HISTEROSCOPIA && $variante === self::UTERO) {
            return 'img/clinica/histeroscopia.svg';
        }

        return null;
    }

    /** El nombre del archivo del esquema, para el `alt` y los textos. */
    public static function nombreDelEsquema(string $examen, string $variante): string
    {
        return mb_strtolower(self::etiquetaDeLaVariante($variante));
    }

    /** La clave del lienzo del esquema de una variante (`esquema:nulipara`). */
    public static function claveDelEsquema(string $variante): string
    {
        return self::MODO_ESQUEMA . ':' . $variante;
    }

    /** La clave del lienzo de una foto (`foto:12`, el id de la fila de `imagen_consulta`). */
    public static function claveDeLaFoto(int $imagen): string
    {
        return self::MODO_FOTO . ':' . $imagen;
    }

    /**
     * El id de la foto de una clave de lienzo (`foto:12` → 12), o `null` si la clave no es de una foto.
     */
    public static function imagenDeLaClave(string $clave): ?int
    {
        return preg_match('/^' . self::MODO_FOTO . ':(\d+)$/', $clave, $coincidencia) === 1 ? (int) $coincidencia[1] : null;
    }

    /**
     * El rótulo de cada modo en el radiobutón: **Imagen predeterminada** y **Videocolposcopio**. Es el vocabulario que
     * pidió Alexander (O-09), tal cual.
     */
    public static function nombreDelModo(string $examen, string $modo): string
    {
        return $modo === self::MODO_FOTO ? self::nombreDeLaFoto($examen) : 'Imagen predeterminada';
    }

    /** Cómo se llama el modo de la foto en ese examen: `Videocolposcopio` (y, en la histeroscopía, `Videohisteroscopio`). */
    public static function nombreDeLaFoto(string $examen): string
    {
        return $examen === self::COLPOSCOPIA ? 'Videocolposcopio' : 'Videohisteroscopio';
    }

    /** La línea que explica cada modo debajo del radiobutón. */
    public static function descripcionDelModo(string $examen, string $modo): string
    {
        if ($modo === self::MODO_FOTO) {
            return 'La foto real del estudio: puedes marcar tus hallazgos encima.';
        }

        return 'El esquema del examen: ' . ($examen === self::COLPOSCOPIA ? 'el cuello uterino' : 'la cavidad uterina')
            . ', con la imagen base que elijas.';
    }
}
