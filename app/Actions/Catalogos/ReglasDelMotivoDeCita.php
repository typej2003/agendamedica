<?php

namespace App\Actions\Catalogos;

use App\Clinica\Pacientes\BusquedaDePacientes;
use InvalidArgumentException;

/**
 * **Las reglas del catálogo de motivos de cita** (WEB-8.1c), escritas una vez para el alta y la edición.
 *
 * Lo que dice la fuente del escritorio (`w_agregar_motivo_cita.srw`, `d_a_m_cita.srd`):
 *
 *  - **El código lo numera el sistema**: el mayor código del catálogo + 1, en **dos dígitos**
 *    (`right("0"+trim(codigo),2)`, `open` y `ue_nuevo`). El código no se edita al abrir una fila.
 *  - **La razón** (`tipo_atencion`) va **en mayúsculas** y hasta 100 caracteres (`edit.case=upper`, `edit.limit=100`).
 *  - El escritorio **no tiene precio, sede ni marca** de citas múltiples: esas tres columnas son de AppDDR
 *    (`office_id`, `precio`, `admite_varias_citas_pendientes`).
 *
 * El `right("0"+n,2)` del escritorio se rompe en el motivo 100 (daría `00` y chocaría con el primero): la web escribe el
 * número tal cual —`100`— desde ahí (la columna `codigo` admite 10 caracteres). El desplegable de citas del escritorio
 * sólo lee los dos primeros caracteres del código (`left(ddlb.text,2)`), así que pasar de 99 motivos de cita por médico no
 * es un caso real; se dice acá para que nadie lo descubra por accidente.
 */
final class ReglasDelMotivoDeCita
{
    public const LARGO_RAZON = 100;

    /** El mayor precio que cabe en una cifra de dinero razonable (y en una columna `double` sin sorpresas). */
    public const PRECIO_MAXIMO = 99999999.99;

    /** Los dígitos mínimos del código: `right("0"+trim(codigo),2)` del escritorio. */
    public const DIGITOS_DEL_CODIGO = 2;

    /**
     * La razón como se guarda: mayúsculas, espacios colapsados y sin sobrantes.
     *
     * @throws InvalidArgumentException si queda vacía o pasa del largo de la columna
     */
    public static function razon(?string $texto): string
    {
        $razon = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));

        if ($razon === '') {
            throw new InvalidArgumentException('El motivo no tiene nombre: escribe la razón de la cita.');
        }
        if (mb_strlen($razon) > self::LARGO_RAZON) {
            throw new InvalidArgumentException('El nombre del motivo no puede pasar de ' . self::LARGO_RAZON . ' caracteres.');
        }

        return $razon;
    }

    /** La razón comparable para saber si dos son la misma: sin tildes, sin mayúsculas, con los espacios colapsados. */
    public static function clave(?string $texto): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));
    }

    /**
     * El precio como se guarda: vacío es «sin precio» (`null`), un número con dos decimales o un error. Acepta la coma
     * decimal («12,5»), aunque el campo del formulario manda siempre punto.
     *
     * @param  mixed  $valor
     *
     * @throws InvalidArgumentException si no es un número, es negativo o pasa del tope
     */
    public static function precio($valor): ?float
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        $texto = is_string($valor) ? str_replace(',', '.', trim($valor)) : $valor;

        if (! is_numeric($texto)) {
            throw new InvalidArgumentException('El precio debe ser un número.');
        }
        if ((float) $texto < 0) {
            throw new InvalidArgumentException('El precio no puede ser negativo.');
        }
        if ((float) $texto > self::PRECIO_MAXIMO) {
            throw new InvalidArgumentException('El precio es demasiado grande.');
        }

        return round((float) $texto, 2);
    }

    /**
     * El código que le toca al motivo nuevo: el mayor **numérico** del catálogo del médico + 1, con dos dígitos como
     * mínimo. Los códigos que no son numéricos (`PRIM`, `ECO4D`: los que creó el móvil o cargó otro sistema) no cuentan.
     *
     * @param  iterable<mixed>  $codigos  todos los códigos del médico, de cualquier sede
     */
    public static function siguienteCodigo(iterable $codigos): string
    {
        $mayor = 0;

        foreach ($codigos as $codigo) {
            $codigo = trim((string) $codigo);

            if ($codigo !== '' && ctype_digit($codigo) && strlen($codigo) <= 9) {
                $mayor = max($mayor, (int) $codigo);
            }
        }

        return str_pad((string) ($mayor + 1), self::DIGITOS_DEL_CODIGO, '0', STR_PAD_LEFT);
    }
}
