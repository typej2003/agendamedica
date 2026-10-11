<?php

namespace App\Clinica\Consulta;

/**
 * **Los "vacíos" del legado, para los textos clínicos de una consulta** (WEB-4.6).
 *
 * En la base conviven tres formas de decir "acá no hay texto": `NULL`, la cadena vacía y el **literal
 * `none`** que el DataWindow del escritorio deja en sus campos (`string title = "none"` es el valor por
 * defecto de un `multilineedit` del `.srw`; en `consultas` hay columnas reales con esa basura —los
 * resúmenes `total_f`/`total_g` la tienen en 23.971 filas de un médico, ver la matriz de paridad, fila
 * 30—). Mostrar `none` en pantalla sería mostrar basura como si fuera un dato clínico, así que las
 * secciones lo leen como "sin texto".
 *
 * **`''` no se muestra nunca**: el middleware `TrimStrings` recorta al escribir, así que un texto con
 * espacios de más es el mismo estado que el vacío.
 *
 * Por qué no se usa `CamposDePaciente::texto()`: ese helper es de la **ficha del paciente** y sólo cubre
 * `null` y `''`. La familia del literal `none` es de los campos que el DataWindow crea, y sumarla al
 * helper del paciente cambiaría el significado de "sin cédula"/"sin nombre" de toda la ficha. Si otra
 * sección de la consulta vuelve a necesitarlo, este es el lugar donde vive.
 */
final class TextoDeLaConsulta
{
    /**
     * El texto del legado, listo para la pantalla: `null` cuando no dice nada (cualquiera de las tres
     * formas), el texto **tal cual** cuando sí (se conservan los saltos de línea y el `\r\n` del
     * escritorio: es el mismo dato que va a ver el médico, no una copia reformateada).
     */
    public static function limpio($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '' || strcasecmp($texto, 'none') === 0) {
            return null;
        }

        return $texto;
    }

    /** ¿Ese texto dice algo? Es la pregunta que usan los estados de las secciones para decir "Sin escribir". */
    public static function hay($valor): bool
    {
        return self::limpio($valor) !== null;
    }
}
