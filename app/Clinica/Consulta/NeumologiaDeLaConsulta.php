<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;

/**
 * **Neumología pediátrica** (postgrado, 2026-10-19): las tres solapas de texto que el escritorio muestra en su
 * pestaña «Neumonología» (`tabpage_24` con su `tab_6`), dentro de *Evaluación física* —como en el escritorio—.
 *
 * De dónde sale cada dato, medido en `w_consulta_principal.srw` (`ue_guardar`, `:2826-2862`):
 *
 * | Solapa | Campo del escritorio | Columna |
 * |---|---|---|
 * | R.X. de tórax | `tabpage_rxtorax.mle_rayosx` | `consultas.rayosx` |
 * | Espirometría | `tabpage_27.mle_espiro` | `consultas.faringe` |
 * | Broncoscopía | `tabpage_28.mle_bronco` | `consultas.broncoscopia` |
 *
 * ⚠️ **La espirometría va a `consultas.faringe`**, que en ginecología es la **citología** (el escritorio reusa la
 * misma columna con significados distintos, como `observacion` en las imágenes). No se puede separar sin tocar el
 * esquema legado: la web escribe la misma columna que el escritorio, y la citología no se ofrece en pediatría.
 *
 * El escritorio tiene además dos solapas que **no son de este módulo** (Laboratorio y Riesgo quirúrgico): son
 * pantallas de otras secciones y no se duplican acá.
 *
 * Sin estado: el mapa del dato y la lectura.
 */
final class NeumologiaDeLaConsulta
{
    /**
     * Las tres solapas, en el orden del escritorio.
     *
     * @var array<string,array{etiqueta:string, columna:string, filas:int, ayuda:string}>
     */
    private const CAMPOS = [
        'rayosx' => [
            'etiqueta' => 'R.X. de tórax',
            'columna'  => 'rayosx',
            'filas'    => 4,
            'ayuda'    => 'El informe de la radiografía de tórax. Es el mismo texto que imprime el escritorio.',
        ],
        'espirometria' => [
            'etiqueta' => 'Espirometría',
            'columna'  => 'faringe',
            'filas'    => 6,
            'ayuda'    => 'El informe de la espirometría. El escritorio lo guarda en la columna de la citología '
                . '(«faringe»); en pediatría esa solapa no existe y no se pisan.',
        ],
        'broncoscopia' => [
            'etiqueta' => 'Broncoscopía',
            'columna'  => 'broncoscopia',
            'filas'    => 4,
            'ayuda'    => 'El informe de la broncoscopía.',
        ],
    ];

    /** @return array<string,array{etiqueta:string, columna:string, filas:int, ayuda:string}> */
    public static function campos(): array
    {
        return self::CAMPOS;
    }

    /** @return array<int,string> las claves de las solapas */
    public static function claves(): array
    {
        return array_keys(self::CAMPOS);
    }

    /**
     * Los textos guardados, por clave de solapa (`null` donde no hay nada).
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta): array
    {
        $textos = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $textos[$clave] = TextoDeLaConsulta::limpio($consulta->{$campo['columna']});
        }

        return $textos;
    }
}
