<?php

namespace App\Clinica\Consulta;

/**
 * Parte del mapa del dato del eco obstétrico del primer trimestre: sus **campos** y sus **bloques**.
 *
 * Se extrajo del DataWindow **vivo** (`ventana_consulta/d_eco_obstetrico_nueva.srd`, el que la ventana
 * asigna de verdad: `w_consulta_principal.srw:22130`), no de un archivo con el mismo nombre en otra carpeta
 * —la lección de las trampas 99 y 102—. Los rótulos son los del escritorio y los `códigos` de los
 * desplegables salen de sus `values` con el formato `texto<TAB>código/…`.
 *
 * ⚠️ **Dos rótulos del escritorio no coinciden con el nombre de su columna**, y se respetan tal cual: la
 * columna **`observacion`** lleva el rótulo ***Conclusión:*** y **`otros`** lleva ***Observaciones:*** (al
 * revés de lo que uno esperaría). Y `saco_gestacional` guarda **`1`/`2`** (Sí/No) mientras las demás
 * casillas de Sí/No guardan **`S`/`N`**: el escritorio no es consistente y la web tampoco lo va a ser.
 */
trait MapaDelEcoObstetrico
{
    /** Un campo de texto libre (los que el escritorio muestra con su rótulo y sin lista). */
    private static function texto(string $columna, string $etiqueta, ?string $ayuda = null): array
    {
        return ['columna' => $columna, 'etiqueta' => $etiqueta, 'tipo' => 'texto', 'ayuda' => $ayuda ?? $etiqueta];
    }

    /** Un campo de número (los que el escritorio mide en mm o gramos). */
    private static function numero(string $columna, string $etiqueta, ?string $ayuda = null): array
    {
        return ['columna' => $columna, 'etiqueta' => $etiqueta, 'tipo' => 'numero', 'ayuda' => $ayuda ?? $etiqueta];
    }

    /**
     * Un campo con lista, con sus pares **texto => código** tal como los guarda el escritorio.
     *
     * @param  array<string,string>  $pares
     */
    private static function lista(string $columna, string $etiqueta, array $pares, ?string $ayuda = null): array
    {
        $opciones = [];
        foreach ($pares as $texto => $codigo) {
            $opciones[] = ['valor' => $codigo, 'texto' => $texto];
        }

        return ['columna' => $columna, 'etiqueta' => $etiqueta, 'tipo' => 'opciones',
            'opciones' => $opciones, 'ayuda' => $ayuda ?? $etiqueta];
    }

    /** Las casillas de Sí/No que el escritorio guarda con `S`/`N`. */
    private static function siNo(string $columna, string $etiqueta, ?string $ayuda = null): array
    {
        return self::lista($columna, $etiqueta, ['Sí' => 'S', 'No' => 'N'], $ayuda);
    }
}
