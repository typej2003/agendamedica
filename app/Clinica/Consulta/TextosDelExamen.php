<?php

namespace App\Clinica\Consulta;

use App\Clinica\Impresion\DocumentoImpreso;

/**
 * **Las solapas del examen que son un texto** (WEB-6.5): la colposcopía, la citología, la histeroscopía y el
 * resultado de exámenes. El escritorio las guarda en **una columna de `consultas`** cada una y las tres
 * primeras cosas de esta tabla salen de su fuente, verificadas el 2026-10-13:
 *
 * ```
 * tab_1.tabpage_examen.tab_7.tabpage_31.mle_4.text   →  consultas.observaciones   (colposcopía)
 * tab_1.tabpage_examen.tab_7.tabpage_33.mle_5.text   →  consultas.faringe         (citología)
 * tab_1.tabpage_examen.tab_7.tabpage_49.mle_18.text  →  consultas.cuello          (histeroscopía)
 * tab_1.tabpage_examen.tab_7.tabpage_48.mle_13.text  →  consultas.oido            (resultado de exámenes)
 * ```
 *
 * (el `UPDATE` está en `w_consulta_principal.srw:2816-2840`; las cuatro columnas comparten la fila de la
 * consulta, así que **la sección no tiene tablas propias**: es texto sobre `consultas`).
 *
 * Lo que cada solapa agrega a su texto, también de la fuente:
 *
 *  - **Plantillas**: `formato_print` por `tipo` —`CO` la colposcopía (`dw_77`, `:3043`), `CI` la citología
 *    (`dw_78`, `:3046`), `RE` el resultado de exámenes (`dw_91`, `:4626`)—. La **histeroscopía no tiene**
 *    en el escritorio (su `tabpage_49` no hace `retrieve` de plantillas), pero la web las ofrece con el tipo `HI` (WEB-11.7).
 *  - **Informe**: la colposcopía (`d_colposcopia_1`/`_2`) y la histeroscopía (`d_histeroscopia`, cuyo título
 *    está en `:27` y su cuerpo es `consultas.cuello` —`column(band=detail ... name=consultas_cuello`, `:29`—
 *    con las fotos del subreporte). La citología y el resultado de exámenes **no tienen hoja**.
 *  - **Imágenes**: la histeroscopía tiene su grilla (`d_imagenes_consul_pac_histero`, la etiqueta `'H'` de
 *    `imagen_consulta.observacion`) y también su subreporte; la colposcopía la suya (`'@'`); la citología y el
 *    resultado de exámenes **no anexan fotos**.
 *  - **Copia en el examen**: sólo la colposcopía se escribe además en `examen_fisico_nuevo.colposcopia` (el
 *    `UPDATE` comentado de `:2765-2766` y el que la ventana dejó vivo), que es de donde la web cae cuando
 *    `consultas.observaciones` está vacía.
 *
 * Una solapa de texto nueva se agrega acá **y** en `Vistas\PestanasDelExamen`, que es la que arma la fila de
 * solapas del navegador.
 */
final class TextosDelExamen
{
    public const COLPOSCOPIA = 'colposcopia';

    public const CITOLOGIA = 'citologia';

    public const HISTEROSCOPIA = 'histeroscopia';

    public const RESULTADOS = 'resultados';

    /**
     * **El tratamiento** (ítem TRATAMIENTO del panel del legado, `tabpage_40`): un texto libre —con las plantillas
     * `formato_print` de tipo `TR`— que el escritorio guarda en `consultas.laringe`
     * (`tab_1.tabpage_40.mle_11.text`, `w_consulta_principal.srw:2762`). No es una solapa del examen: tiene su
     * propio ítem del panel, así que `claves()` no lo incluye salvo que se pida (`$conLasDelPanel`), y es lo que la
     * ruta de guardado sí acepta. Es el «PLAN DE TRATAMIENTO» del resumen médico.
     */
    public const TRATAMIENTO = 'tratamiento';

    /**
     * El mapa del dato, por clave de solapa.
     *
     * `columna` es la de `consultas` que se lee y se escribe; `columna_espejo`, la copia en otra tabla (o
     * `null`); `plantillas`, el `tipo` de `formato_print` (o `null` si no tiene); `documento`, la hoja del motor
     * de impresión (o `null`); `imagenes`, la clave de la solapa en `TagDeImagen` (o `null` si no anexa fotos).
     *
     * @var array<string,array<string,mixed>>
     */
    private const TEXTOS = [
        self::COLPOSCOPIA => [
            'titulo' => 'Colposcopía',
            'articulo' => 'la',
            'icono' => 'bi-search-heart',
            'guardado' => 'Colposcopía guardada.',
            'sin_cambios' => 'La colposcopía no tenía cambios.',
            'columna' => 'observaciones',
            'columna_espejo' => 'colposcopia',
            'plantillas' => 'CO',
            'documento' => DocumentoImpreso::COLPOSCOPIA,
            'imagenes' => self::COLPOSCOPIA,
            'marcador' => 'El hallazgo colposcópico: el epitelio, la zona de transformación, los hallazgos anormales…',
            'ayuda' => 'El informe de la colposcopía: el texto que se imprime en la hoja «INFORME DE COLPOSCOPÍA», '
                . 'con el membrete y (si cargaste imágenes en esta solapa) sus fotos. Se guarda con el botón.',
        ],
        self::CITOLOGIA => [
            'titulo' => 'Citología',
            'articulo' => 'la',
            'icono' => 'bi-clipboard2-pulse',
            'guardado' => 'Citología guardada.',
            'sin_cambios' => 'La citología no tenía cambios.',
            'columna' => 'faringe',
            'columna_espejo' => null,
            'plantillas' => 'CI',
            'documento' => null,
            'imagenes' => null,
            'marcador' => 'El resultado de la citología: la toma, la calidad de la muestra, el informe del laboratorio…',
            'ayuda' => 'El texto de la citología de esta consulta. El escritorio no tiene una hoja propia para '
                . 'este texto: se guarda y queda en la historia del paciente.',
        ],
        self::HISTEROSCOPIA => [
            'titulo' => 'Histeroscopía',
            'articulo' => 'la',
            'icono' => 'bi-camera',
            'guardado' => 'Histeroscopía guardada.',
            'sin_cambios' => 'La histeroscopía no tenía cambios.',
            'columna' => 'cuello',
            'columna_espejo' => null,
            'plantillas' => 'HI',
            'documento' => DocumentoImpreso::HISTEROSCOPIA,
            'imagenes' => self::HISTEROSCOPIA,
            'marcador' => 'El hallazgo histeroscópico: el canal cervical, la cavidad, el endometrio…',
            'ayuda' => 'El informe de la histeroscopía: el texto que se imprime en la hoja «INFORME DE '
                . 'HISTEROSCOPÍA», con el membrete y (si cargaste imágenes en esta solapa) sus fotos.',
        ],
        self::TRATAMIENTO => [
            'panel' => true,
            'titulo' => 'Tratamiento',
            'articulo' => 'el',
            'icono' => 'bi-capsule',
            'guardado' => 'Tratamiento guardado.',
            'sin_cambios' => 'El tratamiento no tenía cambios.',
            'columna' => 'laringe',
            'columna_espejo' => null,
            'plantillas' => 'TR',
            'documento' => null,
            'imagenes' => null,
            'marcador' => 'El plan de tratamiento: las indicaciones generales, el seguimiento, lo que debe cumplir la paciente…',
            'ayuda' => 'El plan de tratamiento de esta consulta, en texto libre o a partir de una plantilla. Aparece como '
                . '«PLAN DE TRATAMIENTO» en el resumen médico. Los medicamentos recetados van en los récipes.',
        ],
        self::RESULTADOS => [
            'titulo' => 'Resultado de exámenes',
            'articulo' => 'el',
            'icono' => 'bi-clipboard2-check',
            'guardado' => 'Resultado de exámenes guardado.',
            'sin_cambios' => 'El resultado de exámenes no tenía cambios.',
            'columna' => 'oido',
            'columna_espejo' => null,
            'plantillas' => 'RE',
            'documento' => null,
            'imagenes' => null,
            'marcador' => 'El resultado de los exámenes pedidos: lo que informó el laboratorio o el estudio…',
            'ayuda' => 'La nota del médico sobre los resultados. Los exámenes pedidos y el resultado de cada uno '
                . '—los `examen_paciente` de la consulta— se cargan en la solapa de laboratorio y estudios.',
        ],
    ];

    /**
     * Las claves de las solapas de texto **del examen**, en el orden del escritorio. Con `$conLasDelPanel` suma los
     * textos que tienen su propio ítem del panel (el tratamiento): es lo que acepta la ruta de guardado, que es la
     * misma para todos.
     */
    public static function claves(bool $conLasDelPanel = false): array
    {
        return array_keys(array_filter(
            self::TEXTOS,
            fn (array $texto) => $conLasDelPanel || empty($texto['panel']),
        ));
    }

    public static function existe(?string $clave, bool $conLasDelPanel = false): bool
    {
        return $clave !== null && in_array($clave, self::claves($conLasDelPanel), true);
    }

    /** @return array<string,mixed>|null */
    public static function de(?string $clave): ?array
    {
        return $clave === null ? null : (self::TEXTOS[$clave] ?? null);
    }

    public static function titulo(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['titulo'] ?? 'Examen');
    }

    public static function icono(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['icono'] ?? 'bi-clipboard2');
    }

    /** La columna de `consultas` donde vive el texto (o `null` si la clave no es una solapa de texto). */
    public static function columna(string $clave): ?string
    {
        return self::TEXTOS[$clave]['columna'] ?? null;
    }

    /** La copia del texto en otra tabla, o `null` (hoy sólo la colposcopía la tiene). */
    public static function columnaEspejo(string $clave): ?string
    {
        return self::TEXTOS[$clave]['columna_espejo'] ?? null;
    }

    /** El `tipo` de `formato_print` de sus plantillas, o `null` si la solapa no tiene. */
    public static function tipoDePlantillas(string $clave): ?string
    {
        return self::TEXTOS[$clave]['plantillas'] ?? null;
    }

    /** La clave del documento en `DocumentoImpreso`, o `null` si el texto no tiene hoja. */
    public static function documento(string $clave): ?string
    {
        return self::TEXTOS[$clave]['documento'] ?? null;
    }

    /** La clave de la solapa en `TagDeImagen` (para las fotos), o `null` si no anexa. */
    public static function imagenes(string $clave): ?string
    {
        return self::TEXTOS[$clave]['imagenes'] ?? null;
    }

    /**
     * La **solapa de texto** cuya hoja es ese documento, o `null` si esa clave de `DocumentoImpreso` no es de
     * una solapa (es el camino inverso de `documento()`, para el motor de impresión).
     */
    public static function deLaHoja(string $documento): ?string
    {
        foreach (self::TEXTOS as $clave => $texto) {
            if (($texto['documento'] ?? null) === $documento) {
                return $clave;
            }
        }

        return null;
    }

    public static function marcador(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['marcador'] ?? '');
    }

    public static function ayuda(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['ayuda'] ?? '');
    }

    /** El aviso del guardado que salió bien (`'Colposcopía guardada.'`). */
    public static function avisoGuardada(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['guardado'] ?? 'Guardado.');
    }

    /** El aviso de que no había nada que cambiar (`'La colposcopía no tenía cambios.'`). */
    public static function avisoSinCambios(string $clave): string
    {
        return (string) (self::TEXTOS[$clave]['sin_cambios'] ?? 'No había cambios.');
    }

    /** El aviso de una columna bloqueada por una edición más nueva de otro equipo. */
    public static function avisoBloqueada(string $clave): string
    {
        $articulo = (string) (self::TEXTOS[$clave]['articulo'] ?? 'el');

        return 'Otro equipo editó ' . $articulo . ' ' . mb_strtolower(self::titulo($clave))
            . ' más recientemente y no se pisó: vuelve a cargarla y guárdala de nuevo.';
    }
}
