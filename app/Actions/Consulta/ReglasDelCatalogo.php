<?php

namespace App\Actions\Consulta;

use InvalidArgumentException;

/**
 * **Las reglas de un catálogo clínico del médico** (motivos de consulta y diagnósticos): la descripción
 * en mayúsculas y el correlativo de cuatro dígitos del código.
 *
 * Vive acá porque el catálogo de **diagnósticos** se comporta **igual** que el de motivos de consulta y la
 * regla no se escribe dos veces (PLAN-WEB.md, R3). Lo que comparten los dos, verificado contra la fuente:
 *
 *  - **La descripción se guarda en mayúsculas** y con los espacios de más colapsados: es lo que hacen los
 *    dos DataWindows de alta (`d_a_motivos_consulta.srd` / `d_a_diagnostico.srd`, los dos con
 *    `edit.case=upper`) y el `mb_strtoupper` con el que el móvil y `AgregarMotivoAlCatalogo` guardan.
 *  - **El código es un correlativo de cuatro dígitos que da el servidor**: la ventana de alta del legado
 *    ordena el catálogo por código, toma el mayor, le suma uno y lo rellena con ceros —motivos en
 *    `w_nuevo_motivo_consulta.srw` y diagnósticos en `w_nuevo_diagnostico.srw:122-137` (`ue_nuevo`) y
 *    `:234-248` (`open`), los dos con `right("000"+trim(codigo),4)`—. Por eso el tope es **9999**: con
 *    `10000` el `right(…,4)` del legado daría `0000` y chocaría con el primer código.
 *  - **Si ya hay uno con la misma descripción, se devuelve el existente** en vez de duplicarlo (el catálogo
 *    del legado trae repetidos —`CANCER` tres veces en `antecedentes`, motivos con dos códigos— y no se
 *    agrega uno más): la comparación la hace quien llama, porque cada catálogo tiene su tabla.
 *
 * Lo que **no** comparten es la tabla, el nombre de la columna del código y el largo de la descripción
 * (`motivos_consulta.descripcion` es de 40 y `diagnosticos.descripcion` de 50, como declaran esos
 * DataWindows), así que el helper recibe esos datos y **no** conoce ninguna tabla.
 */
final class ReglasDelCatalogo
{
    /** El mayor código que el correlativo de cuatro dígitos del legado puede representar. */
    public const CODIGO_MAXIMO = 9999;

    /**
     * La descripción como se guarda: mayúsculas, espacios colapsados y sin sobrantes.
     *
     * @param  string  $singular  cómo se llama el ítem en el mensaje de error ("motivo", "diagnóstico")
     *
     * @throws InvalidArgumentException si queda vacía o pasa del largo de la columna
     */
    public static function descripcion(string $descripcion, int $largoMaximo, string $singular): string
    {
        $descripcion = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $descripcion)));

        if ($descripcion === '') {
            throw new InvalidArgumentException('El ' . $singular . ' no tiene descripción.');
        }
        if (mb_strlen($descripcion) > $largoMaximo) {
            throw new InvalidArgumentException(
                'La descripción del ' . $singular . ' no puede pasar de ' . $largoMaximo . ' caracteres.'
            );
        }

        return $descripcion;
    }

    /**
     * El código que le toca al ítem nuevo: el mayor numérico del catálogo + 1, en cuatro dígitos.
     *
     * Los códigos que no son numéricos se ignoran (el legado tiene basura en los catálogos) y el que
     * decide el tope es `CODIGO_MAXIMO`, que es lo que el `right("000"+…,4)` de la fuente puede escribir.
     *
     * @param  iterable<mixed>  $codigos  los códigos que ya tiene el catálogo del médico
     * @param  string  $plural  cómo se llama el catálogo en el mensaje de error ("motivos", "diagnósticos")
     *
     * @throws InvalidArgumentException si el catálogo está lleno
     */
    public static function siguienteCodigo(iterable $codigos, string $plural): string
    {
        $siguiente = 1;

        foreach ($codigos as $codigo) {
            if (is_numeric($codigo)) {
                $siguiente = max($siguiente, (int) $codigo + 1);
            }
        }

        if ($siguiente > self::CODIGO_MAXIMO) {
            throw new InvalidArgumentException('El catálogo de ' . $plural . ' está lleno.');
        }

        return str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT);
    }
}
