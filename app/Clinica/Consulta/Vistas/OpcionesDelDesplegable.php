<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Las opciones de un desplegable de un estudio ecográfico** (WEB-6.1, WEB-6.2): la lista que el DataWindow
 * declara en su campo, con una opción **vacía** adelante y el **código guardado** al final si no está entre las
 * del escritorio.
 *
 * Existe porque los tres estudios del ítem 5 del panel la necesitan igual, y una de las dos partes no es obvia:
 *
 *  - **La opción vacía es obligatoria.** Un `<select>` sin ninguna opción `selected` manda al servidor la
 *    **primera** de su lista, así que un formulario recién abierto —y un eco al que el médico no le tocó las
 *    casillas— guardaba «Sí» en todas ellas: un hallazgo que nadie escribió. La opción vacía también es un
 *    estado **del legado**: el `values` del DataWindow trae un tercer valor sin código (`Sí	S/No	N/`, o sea
 *    `Sí→S`, `S/No→N` y `N/→` vacío), y sin ella la web no podría dejar el campo en blanco.
 *  - **El código desconocido del escritorio se conserva** (igual que el `codeparentesco` de WEB-3.4): los datos
 *    reales pueden traer cualquier código y no se inventa una traducción ni se cambia el valor sin querer.
 *
 * Sin estado y sin base de datos: es una función de lo que ya está leído.
 */
final class OpcionesDelDesplegable
{
    /**
     * **El valor guardado, resuelto a la clave de la lista.** Un valor viejo que está escrito como el
     * **texto** de una de las opciones —los rótulos que la web guardó en los reactivos del laboratorio antes
     * de la trampa 140— vuelve a su clave (`Negativo` → `N`), así el `<select>` lo muestra elegido. Lo que ya
     * es una clave, o un código desconocido del escritorio, queda como está.
     *
     * @param  array<string,mixed>  $campo  el campo del mapa (`opciones`: la lista del DataWindow)
     */
    public static function valorConocido(array $campo, string $valor): string
    {
        foreach ($campo['opciones'] ?? [] as $opcion) {
            if ($opcion['valor'] === $valor) {
                return $valor;
            }
        }

        foreach ($campo['opciones'] ?? [] as $opcion) {
            if (strcasecmp((string) $opcion['texto'], $valor) === 0) {
                return $opcion['valor'];
            }
        }

        return $valor;
    }

    /**
     * @param  array<string,mixed>  $campo  el campo del mapa (`opciones`: la lista del DataWindow)
     * @param  string  $valor  lo que tiene guardado la fila (`''` si no tiene nada)
     * @return array<int,array{valor:string,texto:string}>
     */
    public static function de(array $campo, string $valor): array
    {
        $opciones = [['valor' => '', 'texto' => '—']];
        $conocido = $valor === '';

        foreach ($campo['opciones'] ?? [] as $opcion) {
            $opciones[] = $opcion;
            $conocido = $conocido || $opcion['valor'] === $valor;
        }

        if (! $conocido) {
            $opciones[] = ['valor' => $valor, 'texto' => $valor . ' (valor anterior)'];
        }

        return $opciones;
    }
}
