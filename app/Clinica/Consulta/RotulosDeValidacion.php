<?php

namespace App\Clinica\Consulta;

/**
 * **Los rótulos humanos de los campos del estudio para los mensajes de validación** (O-15).
 *
 * El mensaje de Laravel nombra el campo con la clave que viaja en el formulario —la columna de la tabla—:
 * «El campo ovarioderap no puede ser negativo». Para el médico eso no es un rótulo, es el nombre de una
 * columna. Esta clase traduce el **mapa del dato** al nombre que se ve en pantalla, con el bloque cuando
 * hace falta para no confundir dos campos iguales:
 *
 *  - `cuerpo` → «cuerpo (longitud)» (la etiqueta ya viene con el paréntesis de la pantalla);
 *  - `transverso` del bloque *Ovario derecho* → «transverso del ovario derecho» (el rótulo solo,
 *    *Transverso*, aparece en el útero y en los dos ovarios: el bloque es el que dice cuál es);
 *  - `longitud` del bloque *Ovario izquierdo* → «longitud del ovario izquierdo».
 *
 * El criterio es uno solo y no se escribe por campo: **si la etiqueta se repite en el mapa, se le agrega
 * el bloque**. Los rótulos que no se repiten se quedan como están («gesta», «volumen»), porque en la
 * pantalla tampoco necesitan más contexto.
 *
 * Sin base de datos: sólo lee el mapa que le pasan (`EcoGinecologico::grupos()`,
 * `EcoObstetrico::grupos()`, etc.).
 */
final class RotulosDeValidacion
{
    /**
     * `columna => rótulo humano` (en minúsculas, sin artículo: el mensaje de `lang/es/validation.php` dice
     * «El campo :attribute …»).
     *
     * @param  array<int,array{titulo:string,campos:array<int,array<string,mixed>>}>  $grupos  el mapa por bloques
     * @return array<string,string>
     */
    public static function deLosGrupos(array $grupos): array
    {
        $veces = self::cuantasVecesSeRepiteCadaEtiqueta($grupos);
        $rotulos = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $etiqueta = trim((string) ($campo['etiqueta'] ?? ''));
                $columna = (string) $campo['columna'];

                // Un campo sin rótulo en el escritorio (el memo grande del ecocardiograma) se nombra con la
                // columna: es lo único que hay y es mejor que un mensaje en inglés.
                if ($etiqueta === '') {
                    $rotulos[$columna] = $columna;
                    continue;
                }

                $rotulo = mb_strtolower($etiqueta);

                if (($veces[$rotulo] ?? 0) > 1) {
                    $rotulo .= ' ' . self::delBloque((string) $grupo['titulo']);
                }

                $rotulos[$columna] = $rotulo;
            }
        }

        return $rotulos;
    }

    /**
     * `etiqueta (en minúsculas) => cuántas veces aparece` en el mapa entero.
     *
     * @param  array<int,array{titulo:string,campos:array<int,array<string,mixed>>}>  $grupos
     * @return array<string,int>
     */
    private static function cuantasVecesSeRepiteCadaEtiqueta(array $grupos): array
    {
        $veces = [];

        foreach ($grupos as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $etiqueta = mb_strtolower(trim((string) ($campo['etiqueta'] ?? '')));

                if ($etiqueta !== '') {
                    $veces[$etiqueta] = ($veces[$etiqueta] ?? 0) + 1;
                }
            }
        }

        return $veces;
    }

    /**
     * El bloque como complemento del rótulo: *Ovario derecho* → «del ovario derecho», *Datos del estudio* →
     * «del estudio» (el bloque no es el sujeto del campo).
     *
     * Un bloque que empieza con artículo («El feto») o que es una lista («Placenta y líquido») no entra en un
     * «del …» sin sonar mal —y el género cambia de un bloque a otro—, así que va **entre paréntesis**: «líquido
     * amniótico (feto)», «líquido amniótico (placenta y líquido)». Es la misma idea —decir cuál de los dos
     * campos es— sin pelearse con el artículo.
     */
    private static function delBloque(string $titulo): string
    {
        $titulo = mb_strtolower(trim($titulo));
        $titulo = (string) preg_replace('/^datos\s+/u', '', $titulo);
        $titulo = trim(explode(',', $titulo)[0]);
        $titulo = (string) preg_replace('/^(el|la|los|las)\s+/u', '', $titulo);

        if (str_contains($titulo, ' y ')) {
            return '(' . $titulo . ')';
        }

        return 'del ' . $titulo;
    }
}
