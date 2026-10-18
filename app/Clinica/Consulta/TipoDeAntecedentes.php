<?php

namespace App\Clinica\Consulta;

use Illuminate\Support\Collection;

/**
 * **Qué tipo de antecedente muestra la sección** (A-06, revisión de Alexander): el `?tipo=` de la pantalla.
 *
 * Desde A-06 los siete tipos del catálogo son **chips** —no un `<select>` con «Todos los tipos»— y siempre hay
 * uno elegido: el chip filtra las dos columnas de la sección (los antecedentes del paciente a la izquierda y el
 * catálogo a la derecha), como la solapa del escritorio. Esta clase decide **cuál** está elegido, y lo decide
 * una sola vez por petición para que las dos columnas no puedan mostrar tipos distintos.
 *
 * Las reglas, en orden:
 *
 *  1. **Lo que pidió el médico** (`?tipo=`), si es uno de los tipos del catálogo (o «Sin clasificar», que es el
 *     último). Un código que no existe se ignora en vez de dejar la pantalla vacía sin explicación.
 *  2. Al **entrar** (sin `?tipo=`): el **primero del catálogo que tenga antecedentes del paciente** —es el que
 *     el médico va a querer mirar— y, si el paciente todavía no tiene ninguno, el **primero del catálogo**, para
 *     que el alta arranque en un tipo concreto.
 *
 * Es una clase sin estado y sin base de datos: recibe los tipos y lo que el paciente tiene ya leídos, así que se
 * prueba sola (`tests/Unit/Clinica/TipoDeAntecedentesTest.php`).
 */
final class TipoDeAntecedentes
{
    /**
     * El código del grupo de los antecedentes del paciente **cuyo tipo no se puede resolver** (la fila no tiene
     * `tipo` y el catálogo tampoco lo resuelve). No es un tipo inventado del catálogo: es una etiqueta de la
     * pantalla, como el `Sin clasificar` de la historia del paciente (WEB-3.5).
     *
     * Existe porque con chips y sin «todos» esas filas **desaparecerían de la pantalla**: son datos clínicos y
     * tienen que poder verse y editarse. El chip se agrega sólo si el paciente tiene alguna.
     */
    public const SIN_CLASIFICAR = 'sin-clasificar';

    /**
     * @param  Collection<int,object{id:int, codigo:string, tipoCodigo:?string, tipoNombre:?string}>  $delPaciente
     * @param  array<int,array{codigo:string, nombre:string}>  $tipos  el catálogo de tipos, en el orden de `codetipo`
     */
    public static function elegido(string $pedido, Collection $delPaciente, array $tipos): string
    {
        $codigos = array_column($tipos, 'codigo');
        $pedido = trim($pedido);

        if ($pedido === self::SIN_CLASIFICAR && self::haySinClasificar($delPaciente, $codigos)) {
            return self::SIN_CLASIFICAR;
        }

        if ($pedido !== '' && in_array($pedido, $codigos, true)) {
            return $pedido;
        }

        // El primero del catálogo que el paciente tenga cargado. El orden de los tipos es el de las solapas del
        // escritorio (Per., Fam., H.P., Obst., Gine., A.S., Otros), no el alfabético.
        foreach ($codigos as $codigo) {
            foreach ($delPaciente as $antecedente) {
                if ((string) $antecedente->tipoCodigo === (string) $codigo) {
                    return $codigo;
                }
            }
        }

        // Ninguno de los siete: si lo único que hay son filas sin tipo, la pantalla abre en ellas (si no, no se
        // verían en ningún chip).
        if (self::haySinClasificar($delPaciente, $codigos)) {
            return self::SIN_CLASIFICAR;
        }

        return $codigos[0] ?? '';
    }

    /**
     * ¿Hay antecedentes del paciente que no caen en ninguno de los tipos del catálogo? Son los que la pantalla
     * muestra en el chip «Sin clasificar».
     *
     * @param  Collection<int,object>  $delPaciente
     * @param  array<int,string>  $codigos
     */
    public static function haySinClasificar(Collection $delPaciente, array $codigos): bool
    {
        foreach ($delPaciente as $antecedente) {
            if (! in_array((string) $antecedente->tipoCodigo, array_map('strval', $codigos), true)) {
                return true;
            }
        }

        return false;
    }
}
