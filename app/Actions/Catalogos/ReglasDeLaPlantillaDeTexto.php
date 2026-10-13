<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Clinica\Pacientes\BusquedaDePacientes;
use InvalidArgumentException;

/**
 * **Las reglas de una plantilla de texto** (`formato_print`, WEB-8.4), en un solo lugar para el alta y la edición:
 *
 *  - **Título**: obligatorio, en mayúsculas y hasta 200 caracteres (`edit.case=upper` y `edit.limit=200` de `d_a_formatos.srd`), con
 *    los espacios de más colapsados.
 *  - **Texto** (`descripcion`): obligatorio —una plantilla sin texto no se ofrece en la consulta— y de hasta {@see self::LARGO_TEXTO}
 *    caracteres. El escritorio admite 32.000, pero la columna de MySQL es `TEXT` (65.535 *bytes*): con 20.000 cabe aun con acentos.
 *    Los saltos de línea se guardan como el navegador los manda (`\r\n`, igual que el escritorio) y la comparación los iguala.
 *  - **Tipo**: uno de los códigos de `TiposDePlantillaDeTexto` (no se inventan tipos). Al editar, el tipo que la fila ya tenía se
 *    conserva aunque no esté en la lista (el legado puede traer cualquiera).
 *  - **Código**: el correlativo de cuatro dígitos del médico (`right("0000"+n, 4)` de `w_agregar_dietas.srw`), con el mayor numérico
 *    + 1; los códigos que no son numéricos (el legado trae basura) no cuentan.
 *  - **No duplicar**: el mismo título (sin tildes ni mayúsculas) **en el mismo tipo**.
 */
final class ReglasDeLaPlantillaDeTexto
{
    public const LARGO_TITULO = 200;

    public const LARGO_TEXTO = 20000;

    /** @throws InvalidArgumentException si queda vacío o pasa del largo */
    public static function titulo(?string $titulo): string
    {
        $titulo = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $titulo)));

        if ($titulo === '') {
            throw new InvalidArgumentException('Escribe el título de la plantilla.');
        }
        if (mb_strlen($titulo) > self::LARGO_TITULO) {
            throw new InvalidArgumentException('El título no puede pasar de ' . self::LARGO_TITULO . ' caracteres.');
        }

        return $titulo;
    }

    /** @throws InvalidArgumentException si queda vacío o pasa del largo */
    public static function texto(?string $texto): string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            throw new InvalidArgumentException('Escribe el texto de la plantilla: sin texto no se ofrece en la consulta.');
        }
        if (mb_strlen($texto) > self::LARGO_TEXTO) {
            throw new InvalidArgumentException('El texto no puede pasar de ' . self::LARGO_TEXTO . ' caracteres.');
        }

        return $texto;
    }

    /**
     * @param  ?string  $actual  el tipo que la fila ya tenía (al editar): se acepta aunque no esté en la lista
     *
     * @throws InvalidArgumentException si no es un tipo de la lista
     */
    public static function tipo(?string $tipo, ?string $actual = null): string
    {
        $tipo = strtoupper(trim((string) $tipo));

        if ($tipo !== '' && (TiposDePlantillaDeTexto::conocido($tipo) || $tipo === strtoupper(trim((string) $actual)))) {
            return $tipo;
        }

        throw new InvalidArgumentException('Elige el tipo de plantilla.');
    }

    /** El título como se compara para no duplicar: sin tildes, sin mayúsculas, con los espacios colapsados. */
    public static function clave(?string $titulo): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $titulo)));
    }

    /** El texto para comparar si cambió: los saltos de línea del navegador (`\r\n`) y los de un archivo (`\n`) son lo mismo. */
    public static function textoComparable(?string $texto): string
    {
        return trim(str_replace("\r\n", "\n", (string) $texto));
    }

    /**
     * El código que le toca a la plantilla nueva.
     *
     * @param  iterable<mixed>  $codigos  los códigos que ya tiene el médico
     *
     * @throws InvalidArgumentException si el correlativo de cuatro dígitos se agotó
     */
    public static function siguienteCodigo(iterable $codigos): string
    {
        return ReglasDelCatalogo::siguienteCodigo($codigos, 'plantillas de texto', 4);
    }
}
