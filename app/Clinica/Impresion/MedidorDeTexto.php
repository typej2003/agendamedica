<?php

namespace App\Clinica\Impresion;

/**
 * **Cuánto ocupa un texto impreso**, sin navegador: el ancho de cada letra de Arimo (la fuente métricamente
 * idéntica a Arial/Helvetica, con la que se imprime el cuerpo de los documentos) y el corte de líneas por
 * palabra, para saber cuántas líneas toma un texto en un ancho dado.
 *
 * Existe porque la hoja de un documento es de **alto fijo** (media hoja A4) y el navegador no sabe pasar a
 * una segunda hoja con su propio membrete y firma: quien decide si el texto entra y dónde se parte es el
 * servidor (`DistribuidorDeRecipe`), y necesita medir sin dibujar. El cálculo es **conservador a propósito**
 * —un margen de seguridad en el ancho y las líneas redondeadas hacia arriba—: equivocarse por exceso deja un
 * poco de aire en la hoja; equivocarse por defecto encima el texto sobre la firma.
 *
 * Los anchos son los de la tabla AFM de Helvetica (milésimas de em). Las letras con tilde miden lo mismo que
 * la base. Un carácter fuera de la tabla se mide como una letra ancha (600).
 *
 * Es una clase **sin estado y sin base de datos**: se prueba con textos de entrada y líneas de salida.
 */
final class MedidorDeTexto
{
    /** Un punto tipográfico en milímetros. */
    public const MM_POR_PUNTO = 0.3528;

    /** El ancho que se da por bueno: lo que el navegador gana o pierde por kerning y redondeos. */
    private const MARGEN_DE_ANCHO = 0.94;

    private const ANCHO_POR_DEFECTO = 600;

    /** Helvetica regular, milésimas de em, por carácter. */
    private const REGULAR = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    /** Helvetica negrita: sólo lo que difiere de la regular. */
    private const NEGRITA = [
        '!' => 333, '"' => 474, '%' => 889, '&' => 722, "'" => 238, ')' => 333, ':' => 333, ';' => 333,
        '?' => 611, '@' => 975, 'A' => 722, 'B' => 722, 'J' => 556, 'K' => 722, 'L' => 611, '[' => 333,
        ']' => 333, '^' => 584, '`' => 333, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'f' => 333,
        'g' => 611, 'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611,
        'o' => 611, 'p' => 611, 'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556,
        'w' => 778, 'x' => 556, 'y' => 556, '{' => 389, '|' => 280, '}' => 389,
    ];

    /** Las letras con tilde miden lo mismo que su base. */
    private const BASE = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
        '¿' => '?', '¡' => '!', 'º' => 'o', 'ª' => 'a',
    ];

    /** El ancho de un texto en milímetros (una sola línea) a [$puntos] pt. */
    public function ancho(string $texto, float $puntos, bool $negrita = false): float
    {
        $milesimas = 0;

        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            $milesimas += $this->anchoDe($caracter, $negrita);
        }

        return $milesimas / 1000 * $puntos * self::MM_POR_PUNTO;
    }

    /**
     * Cuántas líneas ocupa [$texto] en [$anchoMm] milímetros a [$puntos] pt. Respeta los saltos de línea
     * (cada uno empieza un párrafo; una línea en blanco cuenta) y corta por palabra; una palabra más larga
     * que la línea se parte donde haga falta, como el navegador con `overflow-wrap: anywhere`.
     *
     * Un texto vacío ocupa **cero** líneas.
     */
    public function lineas(string $texto, float $anchoMm, float $puntos, bool $negrita = false): int
    {
        $texto = trim(str_replace(["\r\n", "\r"], "\n", $texto));

        if ($texto === '') {
            return 0;
        }

        $disponible = max($anchoMm * self::MARGEN_DE_ANCHO, 1.0);
        $lineas = 0;

        foreach (explode("\n", $texto) as $parrafo) {
            $lineas += $this->lineasDelParrafo(trim($parrafo), $disponible, $puntos, $negrita);
        }

        return max($lineas, 1);
    }

    private function lineasDelParrafo(string $parrafo, float $disponible, float $puntos, bool $negrita): int
    {
        if ($parrafo === '') {
            return 1;
        }

        $espacio = $this->ancho(' ', $puntos, $negrita);
        $lineas = 1;
        $usado = 0.0;

        foreach (preg_split('/\s+/u', $parrafo, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $ancho = $this->ancho($palabra, $puntos, $negrita);

            if ($usado > 0 && $usado + $espacio + $ancho > $disponible) {
                $lineas++;
                $usado = 0.0;
            }

            if ($ancho > $disponible) {
                // La palabra sola no entra: ocupa las líneas que haga falta.
                $extra = (int) ceil($ancho / $disponible) - 1;
                $lineas += $extra;
                $usado = $ancho - $extra * $disponible;

                continue;
            }

            $usado += ($usado > 0 ? $espacio : 0.0) + $ancho;
        }

        return $lineas;
    }

    private function anchoDe(string $caracter, bool $negrita): int
    {
        $caracter = self::BASE[$caracter] ?? $caracter;

        if ($negrita && isset(self::NEGRITA[$caracter])) {
            return self::NEGRITA[$caracter];
        }

        return self::REGULAR[$caracter] ?? self::ANCHO_POR_DEFECTO;
    }
}
