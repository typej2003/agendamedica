<?php

namespace App\Actions\Consulta;

/**
 * **El botón «Actualizar» del examen** (WEB-11.8): arma los dos resúmenes narrativos que el escritorio guarda en
 * `examen_fisico_nuevo.total_f` (examen físico) y `total_g` (examen ginecológico) a partir de los datos **no vacíos** del examen.
 *
 * Es la misma cuenta del `clicked` de `cb_1` (`w_consulta_principal.srw:8960-9010`, el físico) y de `cb_2` (`:9882-9907`, el
 * ginecológico): cada dato no vacío aporta `ROTULO: valor.  ` (punto y dos espacios) en un orden fijo y el texto termina en `.`.
 * Lo que cambia respecto del escritorio: **no se calcula la relación cintura/cadera** (la web no captura la cadera) y un examen sin
 * ningún dato da `''` en vez de un `.` suelto.
 *
 * Es una función pura —sin base de datos ni HTTP— para que la use igual la web y, después, el móvil (R3 de PLAN-WEB.md). Quien la
 * llama decide si pisa un resumen que el médico ya editó (la pantalla pide confirmación): acá sólo se arma el texto.
 */
final class ArmarResumenDelExamen
{
    /** El separador del escritorio (`ESPAC = '.  '`). */
    private const ESPACIO = '.  ';

    /**
     * El resumen del **examen físico**: peso, talla, tensión, circunferencia abdominal y el examen por aparatos.
     *
     * @param  array<string,mixed>  $datos  columnas de `consultas` y `examen_fisico_nuevo` (peso, talla, tension, circabdominal, general…)
     */
    public static function fisico(array $datos): string
    {
        return self::armar($datos, [
            ['peso', 'PESO', ' kg'],
            ['talla', 'TALLA', ' m'],
            ['tension', 'TENSIÓN ARTERIAL', ''],
            ['circabdominal', 'CIRCUNFERENCIA ABDOMINAL', ' Cm'],
            ['general', 'GENERAL', ''],
            ['cardiopulmonar', 'CARDIOPULMONAR', ''],
            ['tiroides', 'TIROIDES', ''],
            // Los rótulos de las mamas están cambiados respecto de las columnas (trampa del legado): `mamas` es la derecha.
            ['mamas', 'MAMA DERECHA', ''],
            ['mama_derecha', 'MAMA IZQUIERDA', ''],
            ['abdomen', 'ABDOMEN', ''],
            ['extremidades', 'EXTREMIDADES', ''],
            ['neurologico', 'NEUROLOGICO', ''],
        ]);
    }

    /**
     * El resumen del **examen físico de pediatría** (WEB-12.2): `ue_fisico` del escritorio (`w_consulta_principal.srw:11424`) —peso, talla y
     * circunferencia cefálica de las mediciones, y los cuadros del examen con **los rótulos de la pantalla** (no los de sus columnas)—.
     * La temperatura no se guarda en ninguna tabla (`em_tem` sólo la usa este resumen) y por eso no entra.
     *
     * @param  array<string,mixed>  $datos  `peso`, `talla`, `c_cefalica` (de las mediciones) y las columnas del examen (`cabeza`…`otros`, `neurologico`)
     */
    public static function pediatrico(array $datos): string
    {
        return self::armar($datos, [
            ['peso', 'PESO', ' Kg'],
            ['talla', 'TALLA', ' Cm'],
            ['c_cefalica', 'CIRCUNFERENCIA CEFÁLICA', ' Cm'],
            ['cabeza', 'CONDICIONES GENERALES', ''],
            ['torax', 'PIEL', ''],
            ['abdomen', 'CABEZA', ''],
            ['pelvis', 'ORL', ''],
            ['genitales', 'TÓRAX', ''],
            ['brazos', 'ABDOMEN', ''],
            ['piernas', 'GENITALES', ''],
            ['otros', 'EXTREMIDADES', ''],
            ['neurologico', 'NEUROLÓGICO', ''],
        ]);
    }

    /**
     * El resumen del **examen ginecológico**: genitales externos, espéculos, tactos, colposcopía y citología.
     *
     * @param  array<string,mixed>  $datos  `genitales_externos`, `especulos`, `tacto_vaginal`, `tacto_rectal`, `colposcopia`, `citologia`
     */
    public static function ginecologico(array $datos): string
    {
        return self::armar($datos, [
            ['genitales_externos', 'GENITALES EXTERNOS', ''],
            ['especulos', 'ESPÉCULOS', ''],
            ['tacto_vaginal', 'TACTO VAGINAL', ''],
            ['tacto_rectal', 'TACTO RECTAL', ''],
            ['colposcopia', 'COLPOSCOPÍA', ''],
            ['citologia', 'CITOLOGÍA', ''],
        ]);
    }

    /**
     * @param  array<string,mixed>  $datos
     * @param  array<int,array{0:string,1:string,2:string}>  $campos  `[clave, rótulo, unidad]` en el orden del resumen
     */
    private static function armar(array $datos, array $campos): string
    {
        $texto = '';

        foreach ($campos as [$clave, $rotulo, $unidad]) {
            $valor = self::valor($datos[$clave] ?? null);

            if ($valor !== null) {
                $texto .= $rotulo . ': ' . $valor . $unidad . self::ESPACIO;
            }
        }

        return $texto === '' ? '' : $texto . '.';
    }

    /** El dato como texto, o `null` si está vacío (`null`, `''` o el literal `none` que deja el escritorio). */
    private static function valor($crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        $texto = is_float($crudo) || is_int($crudo) ? (string) $crudo : trim((string) $crudo);

        return ($texto === '' || strcasecmp($texto, 'none') === 0) ? null : $texto;
    }
}
