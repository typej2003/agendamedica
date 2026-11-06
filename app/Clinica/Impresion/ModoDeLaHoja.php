<?php

namespace App\Clinica\Impresion;

/**
 * **El modo de la hoja de cada documento** (O-26): la **única** regla que dice cómo se imprime un documento —en **media hoja
 * horizontal** (dos por Carta), en **hoja completa vertical** (una por Carta), en la **mitad superior de una Carta vertical** (una por
 * página, con la mitad de abajo en blanco), en la **media hoja horizontal sola** (una por página, con la mitad derecha en
 * blanco) o en la **media hoja vertical** (la página es la mitad de una Carta vertical y el contenido la ocupa entera)—.
 *
 * Vive acá, al lado de las medidas (`MedidasDeLaMitad`), porque el motor de documentos no repite la decisión por documento: un
 * documento **declara** su modo —en los mapas de abajo, con su clave— y `HojasDeDocumentoVista` le pide a esta clase las
 * medidas y cuántos documentos entran por página. El reparto del texto sigue siendo el de `DistribuidorDeDocumento`, que sólo
 * cambia los milímetros con los que mide.
 *
 * ## Qué va en cada modo
 *
 *  - **Hoja completa vertical** (decisión del usuario, 2026-10-17): el informe del **control de embarazo** y los informes de
 *    **los siete ecos** (pélvico, obstétrico del 1.º, del 2.º y del 3.er trimestre, Doppler, mamario, abdominal y cardiograma
 *    fetal).
 *  - **Mitad superior de una Carta vertical** (2026-10-18, Alexander): los informes de **colposcopía y histeroscopía**. La página
 *    es una Carta vertical y el contenido va en la mitad de arriba, como el escritorio, con la imagen del examen **en la misma
 *    página** (el subreporte de la banda *detail* de `d_colposcopia_1` y `d_histeroscopia`). La mitad de abajo queda en
 *    blanco a propósito.
 *  - **Carta vertical, completa o media vertical a elección** (2026-10-21, como el «Página: Grande / Pequeña» del escritorio):
 *    las solicitudes —informe médico, reposo, constancia, referencia, hospitalización, dieta, laboratorio (también el de la
 *    pareja) y estudios—. Por defecto, la completa.
 *  - **Media hoja horizontal**: todo lo demás — los récipes y el resumen médico—.
 *  - **Media hoja horizontal sola**: la misma mitad izquierda de la Carta horizontal que `MITAD`, pero un documento por página (la
 *    mitad derecha queda en blanco). Es una de las tres hojas que el médico elige por imprimible.
 *  - **Media hoja vertical** (2026-11-05): la mitad de arriba de una Carta vertical, pero **la página del PDF mide la mitad**
 *    (215,9 × 139,7 mm) y el contenido la ocupa entera, así que no queda el espacio de abajo. También es una de
 *    las tres que elige el médico.
 *
 * ## La hoja que elige el médico, por imprimible
 *
 * Desde el 2026-11-05 el médico elige la hoja de **cada documento de texto** —no una sola para todos— con el botón de
 * configuración que está al lado de «Imprimir» en la vista previa. Las tres opciones son `completa`, `media-vertical` y
 * `media-horizontal` (`HojaDelImprimible`, que guarda el mapa en `recipe_formatos.hojas_de_los_imprimibles` y lo traduce a
 * los modos de esta clase). Llega acá como el modo ya resuelto (`HojaDelImprimible::modoDe()`), en el tercer parámetro de
 * `de()`; `null` es «la hoja de siempre».
 *
 * Los **récipes no la leen**: siguen siempre en media hoja horizontal, y las solicitudes conservan además el
 * `?pagina=grande|pequena` histórico de una impresión concreta, que manda sobre lo guardado. Los ecos, el control de
 * embarazo, la colposcopía, la histeroscopía y el informe preoperatorio tienen su hoja fija y no entran en la elección.
 *
 * ## La página de cada hoja
 *
 * La completa y la mitad superior usan la **página vertical**; la media hoja compartida y la media horizontal sola usan el
 * **Carta acostada**, y la media vertical, una página del tamaño de la mitad. Las páginas con nombre están en
 * `public/css/clinica-documento.css` (`@page documento-completo`, `documento-media-vertical`, `documento-media-horizontal`)
 * y se eligen con la propiedad `page` del `<article>`: así un lote mixto —un récipe, una constancia en media vertical y un
 * eco— sale cada hoja en su tamaño.
 *
 * Los ecos imprimen además su **informe en formulario** (WEB-11.13), que no sale de acá: lo pinta `_informe-eco` a página
 * completa. Esta clase decide la hoja de los documentos que se reparten como texto (`DistribuidorDeDocumento`), que es el caso
 * del control de embarazo y de **los formatos en blanco** de los ecos (O-26).
 *
 * El **formato en blanco** de un documento lee su modo de acá también (`HojaEnBlancoVista`): la hoja en blanco y la hoja real
 * salen en el mismo tamaño y con la misma orientación.
 *
 * Es una clase **sin estado y sin base de datos**.
 */
final class ModoDeLaHoja
{
    /** Media hoja: la mitad izquierda de una Carta horizontal (dos documentos por página). */
    public const MITAD = 'mitad';

    /** Hoja completa: una Carta vertical entera (un documento por página). */
    public const COMPLETA = 'completa';

    /** La mitad superior de una Carta vertical: el documento usa 139,7 mm de una página vertical y el resto queda en blanco. */
    public const MITAD_SUPERIOR = 'mitad-superior';

    /**
     * Media hoja horizontal **sola**: la misma mitad izquierda de la Carta horizontal que `MITAD`, pero un documento por página
     * (la mitad derecha queda en blanco). Es una de las tres hojas que el médico puede elegir para sus documentos de texto.
     */
    public const HORIZONTAL = 'horizontal';

    /**
     * Media hoja vertical (2026-11-05): la mitad de arriba de una Carta vertical, pero la **página del PDF mide la mitad**
     * (215,9 × 139,7 mm) y el contenido la ocupa entera. Es una de las tres hojas que elige el médico.
     */
    public const MEDIA_VERTICAL = 'media-vertical';

    /** Los modos de hoja que puede devolver `de()`; se usa para no aceptar cualquier texto guardado. @var array<int,string> */
    private const MODOS = [self::MITAD, self::COMPLETA, self::MITAD_SUPERIOR, self::HORIZONTAL, self::MEDIA_VERTICAL];

    /** «Página grande» del escritorio: la Carta vertical completa. Es la que sale si no se elige. */
    public const PAGINA_GRANDE = 'grande';

    /** «Página pequeña» del escritorio: la media hoja vertical. */
    public const PAGINA_PEQUENA = 'pequena';

    /**
     * Los documentos que se imprimen en Carta vertical con la **página a elegir** (grande o pequeña).
     *
     * @var array<int,string>
     */
    private const PAGINA_A_ELEGIR = [
        DocumentoImpreso::INFORME,
        DocumentoImpreso::REPOSO,
        DocumentoImpreso::CONSTANCIA,
        DocumentoImpreso::REFERENCIA,
        DocumentoImpreso::HOSPITALIZACION,
        DocumentoImpreso::DIETA,
        DocumentoImpreso::LABORATORIO,
        DocumentoImpreso::LABORATORIO_PAREJA,
        DocumentoImpreso::ESTUDIOS,
    ];

    /**
     * Los documentos que declaran **hoja completa vertical**.
     *
     * @var array<int,string>
     */
    private const HOJA_COMPLETA = [
        DocumentoImpreso::ECO_PELVICO,
        DocumentoImpreso::ECO_OBSTETRICO,
        DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
        DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
        DocumentoImpreso::ECO_DOPPLER,
        DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
        DocumentoImpreso::ECO_MAMARIO,
        DocumentoImpreso::ECO_ABDOMINAL,
        DocumentoImpreso::CONTROL_DE_EMBARAZO,
    ];

    /**
     * Los documentos que declaran la **mitad superior de una Carta vertical** (su informe lleva la imagen del examen en la misma
     * página, y el escritorio imprime esos dos informes en media hoja).
     *
     * @var array<int,string>
     */
    private const MITAD_SUPERIOR_DE_UN_A4 = [
        DocumentoImpreso::COLPOSCOPIA,
        DocumentoImpreso::HISTEROSCOPIA,
    ];

    /**
     * El modo de un documento, por su clave (`eco-pelvico`, `constancia`, `control-de-embarazo`…).
     *
     * `$pagina` es el `?pagina=grande|pequena` histórico de una impresión concreta: sólo cuenta en los documentos con la
     * página a elegir (`PAGINA_GRANDE`/`PAGINA_PEQUENA`) y **manda sobre `$preferido`**. `$preferido` es la hoja que el
     * médico guardó para ese imprimible, ya resuelta a un modo (`HojaDelImprimible::modoDe`); sólo se aplica a los
     * documentos de texto (`aceptaLaPreferencia`). Con los dos en `null` la hoja es la de siempre.
     */
    public static function de(string $clave, ?string $pagina = null, ?string $preferido = null): string
    {
        if (self::aceptaLaPreferencia($clave)) {
            // El `?pagina=` histórico es de las solicitudes; el resumen médico no lo tiene y sólo lee la preferencia.
            if ($pagina !== null && self::seEligeLaPagina($clave)) {
                return self::pagina($pagina) === self::PAGINA_PEQUENA ? self::MEDIA_VERTICAL : self::COMPLETA;
            }

            if ($preferido !== null && in_array($preferido, self::MODOS, true)) {
                return $preferido;
            }
        }

        if (self::seEligeLaPagina($clave)) {
            return self::pagina($pagina) === self::PAGINA_PEQUENA ? self::MEDIA_VERTICAL : self::COMPLETA;
        }

        if (in_array($clave, self::HOJA_COMPLETA, true)) {
            return self::COMPLETA;
        }

        return in_array($clave, self::MITAD_SUPERIOR_DE_UN_A4, true) ? self::MITAD_SUPERIOR : self::MITAD;
    }

    /**
     * ¿Ese documento entra en la hoja que elige el médico? Son los de texto: las nueve solicitudes y el resumen médico.
     * Los récipes, los ecos, el control de embarazo, la colposcopía, la histeroscopía y el preoperatorio tienen su hoja
     * fija.
     */
    public static function aceptaLaPreferencia(string $clave): bool
    {
        return self::seEligeLaPagina($clave) || $clave === DocumentoImpreso::RESUMEN_MEDICO;
    }

    /** ¿Ese documento se imprime con la página a elegir (grande o pequeña)? */
    public static function seEligeLaPagina(string $clave): bool
    {
        return in_array($clave, self::PAGINA_A_ELEGIR, true);
    }

    /** La página pedida, normalizada: cualquier cosa que no sea la pequeña es la grande. */
    public static function pagina(?string $pagina): string
    {
        return $pagina === self::PAGINA_PEQUENA ? self::PAGINA_PEQUENA : self::PAGINA_GRANDE;
    }

    /** ¿Ese documento va en hoja completa vertical? */
    public static function esCompleta(string $clave): bool
    {
        return self::de($clave) === self::COMPLETA;
    }

    /** ¿Ese documento usa la mitad superior de una página vertical (y no la media hoja horizontal)? */
    public static function esMitadSuperior(string $clave): bool
    {
        return self::de($clave) === self::MITAD_SUPERIOR;
    }

    /**
     * Las medidas con las que se mide y se reparte ese modo: la media hoja de siempre, la Carta vertical completa o su mitad
     * superior. La media hoja sola (`HORIZONTAL`) y la media vertical (`MEDIA_VERTICAL`) miden lo mismo que `MITAD` y
     * `MITAD_SUPERIOR`: lo único que cambia es el tamaño de la página y cuántos entran por página.
     *
     * Es lo único que el distribuidor necesita saber del modo; el resto de la cuenta es la misma.
     */
    public static function medidas(string $modo): MedidasDeLaMitad
    {
        if ($modo === self::COMPLETA) {
            return MedidasDeLaMitad::deLaHojaCompleta();
        }

        return in_array($modo, [self::MITAD_SUPERIOR, self::MEDIA_VERTICAL], true)
            ? MedidasDeLaMitad::deLaMitadSuperior()
            : new MedidasDeLaMitad();
    }

    /**
     * Cuántos documentos entran en **una página** de ese modo: dos en la hoja horizontal compartida (`MITAD`) y uno solo en
     * las cuatro hojas que dejan el resto de la página en blanco (la completa, la mitad superior, la media horizontal sola y
     * la media vertical).
     */
    public static function porPagina(string $modo): int
    {
        return $modo === self::MITAD ? 2 : 1;
    }

    /**
     * La clase CSS del `<article>` de una hoja de ese modo: la completa y la mitad superior comparten la **página vertical
     * con nombre** (`recipe-hoja-completa`), la mitad superior suma la clase que recorta el alto de la mitad y cada una de
     * las dos medias hojas nuevas lleva la suya, que fija el tamaño de la página.
     */
    public static function clasesDeLaHoja(string $modo): string
    {
        return match ($modo) {
            self::COMPLETA => ' recipe-hoja-completa',
            self::MITAD_SUPERIOR => ' recipe-hoja-completa recipe-hoja-mitad-superior',
            self::MEDIA_VERTICAL => ' recipe-hoja-media-vertical',
            self::HORIZONTAL => ' recipe-hoja-horizontal',
            default => '',
        };
    }
}
