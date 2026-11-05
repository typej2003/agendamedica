<?php

namespace App\Clinica\Impresion;

/**
 * **El modo de la hoja de cada documento** (O-26): la **única** regla que dice cómo se imprime un documento —en **media hoja
 * horizontal** (dos por A4), en **hoja completa vertical** (una por A4), en la **mitad superior de un A4 vertical** (una por
 * página, con la mitad de abajo en blanco) o en la **media hoja horizontal sola** (una por A4, con la mitad derecha en
 * blanco)—.
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
 *  - **Mitad superior de un A4 vertical** (2026-10-18, Alexander): los informes de **colposcopía y histeroscopía**. Usan media
 *    hoja —el contenido en la mitad de arriba de una página vertical, como el escritorio— y llevan la imagen del examen **en la
 *    misma página**, debajo del texto (el subreporte de la banda *detail* de `d_colposcopia_1` y `d_histeroscopia`). La mitad
 *    de abajo de la hoja queda en blanco a propósito.
 *  - **A4 vertical, completa o mitad superior a elección** (2026-10-21, como el «Página: Grande / Pequeña» del escritorio): las
 *    solicitudes — informe médico, reposo, constancia, referencia, hospitalización, dieta, laboratorio (también el de la
 *    pareja) y estudios. Por defecto, la grande; nunca media hoja horizontal.
 *  - **Media hoja horizontal**: todo lo demás — los récipes y el resumen médico.
 *  - **Media hoja horizontal sola** (2026-11-04): una opción más para los documentos de texto, con la misma geometría que la
 *    media hoja pero **un documento por A4** (la mitad derecha queda en blanco). No la declara ningún documento: la elige el
 *    médico.
 *
 * ## La hoja que elige el médico
 *
 * Desde el 2026-11-04 el médico puede elegir una de **cuatro** formas para sus documentos de texto (las solicitudes y el
 * resumen médico): vertical, media vertical, horizontal o estilo récipe (`HOJAS_DE_LOS_IMPRIMIBLES`). Se guarda en
 * `recipe_formatos.formato_imprimibles` y llega acá como el modo ya resuelto (`deLaPreferencia`); `NULL` es «como hasta hoy».
 * Los **récipes no la leen**: siguen siempre en media hoja horizontal, y las solicitudes conservan además el `?pagina=`
 * histórico de una impresión concreta, que manda sobre lo guardado. Los ecos, el control de embarazo, la colposcopía, la
 * histeroscopía y el informe preoperatorio tienen su hoja fija y no entran en la elección.
 *
 * **Oculta desde el 2026-11-05:** era **una sola** hoja para todos los reportes, así que se retiró de la vista previa y de
 * «Formato de impresión» hasta que la elección sea **por reporte** (`hojaUnicaHabilitada()`). Con la bandera apagada —el
 * default— la preferencia guardada se ignora y cada documento sale como hasta hoy.
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
    /** Media hoja: la mitad izquierda de un A4 horizontal (dos documentos por página). */
    public const MITAD = 'mitad';

    /** Hoja completa: un A4 vertical entero (un documento por página). */
    public const COMPLETA = 'completa';

    /** La mitad superior de un A4 vertical: el documento usa 148,25 mm de una página vertical y el resto queda en blanco. */
    public const MITAD_SUPERIOR = 'mitad-superior';

    /**
     * Media hoja horizontal **sola**: la misma mitad izquierda del A4 horizontal que `MITAD`, pero un documento por página
     * (la mitad derecha queda en blanco). Es una de las cuatro hojas que el médico puede elegir para sus documentos de texto.
     */
    public const HORIZONTAL = 'horizontal';

    /** Las cuatro hojas que el médico elige para sus documentos de texto (`HOJAS_DE_LOS_IMPRIMIBLES`). */
    public const HOJA_VERTICAL = 'vertical';

    public const HOJA_MEDIA_VERTICAL = 'media-vertical';

    public const HOJA_HORIZONTAL = 'horizontal';

    public const HOJA_RECIPE = 'recipe';

    /** @var array<int,string> */
    public const HOJAS_DE_LOS_IMPRIMIBLES = [
        self::HOJA_VERTICAL,
        self::HOJA_MEDIA_VERTICAL,
        self::HOJA_HORIZONTAL,
        self::HOJA_RECIPE,
    ];

    /** Los modos de hoja que puede devolver `de()`; se usa para no aceptar cualquier texto guardado. @var array<int,string> */
    private const MODOS = [self::MITAD, self::COMPLETA, self::MITAD_SUPERIOR, self::HORIZONTAL];

    /** «Página grande» del escritorio: el A4 vertical completo. Es la que sale si no se elige. */
    public const PAGINA_GRANDE = 'grande';

    /** «Página pequeña» del escritorio: la mitad superior de un A4 vertical. */
    public const PAGINA_PEQUENA = 'pequena';

    /**
     * Los documentos que se imprimen en A4 vertical con la **página a elegir** (grande o pequeña).
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
     * Los documentos que declaran la **mitad superior de un A4 vertical** (su informe lleva la imagen del examen en la misma
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
     * médico guardó, ya resuelta a un modo (`deLaPreferencia`); sólo se aplica a los documentos de texto
     * (`aceptaLaPreferencia`). Con los dos en `null` la hoja es la de siempre.
     */
    public static function de(string $clave, ?string $pagina = null, ?string $preferido = null): string
    {
        if (self::aceptaLaPreferencia($clave)) {
            // El `?pagina=` histórico es de las solicitudes; el resumen médico no lo tiene y sólo lee la preferencia.
            if ($pagina !== null && self::seEligeLaPagina($clave)) {
                return self::pagina($pagina) === self::PAGINA_PEQUENA ? self::MITAD_SUPERIOR : self::COMPLETA;
            }

            if ($preferido !== null && in_array($preferido, self::MODOS, true)) {
                return $preferido;
            }
        }

        if (self::seEligeLaPagina($clave)) {
            return self::pagina($pagina) === self::PAGINA_PEQUENA ? self::MITAD_SUPERIOR : self::COMPLETA;
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

    /**
     * La hoja guardada por el médico (`recipe_formatos.formato_imprimibles`) traducida al modo del motor. `null` o un
     * valor que no es de los cuatro es «sin preferencia»: se queda la hoja de siempre.
     */
    public static function deLaPreferencia(?string $preferencia): ?string
    {
        return match ($preferencia) {
            self::HOJA_VERTICAL => self::COMPLETA,
            self::HOJA_MEDIA_VERTICAL => self::MITAD_SUPERIOR,
            self::HOJA_HORIZONTAL => self::HORIZONTAL,
            self::HOJA_RECIPE => self::MITAD,
            default => null,
        };
    }

    /**
     * ¿Está habilitada la **hoja única** para todos los documentos de texto? (2026-11-05)
     *
     * Apagada por defecto: la elección de una sola hoja para todos los reportes está oculta hasta que cada reporte tenga la
     * suya. Mientras esté apagada no se ofrece en «Formato de impresión» ni en la vista previa, y la preferencia guardada
     * se ignora: todo sale como hasta hoy. Cuando se extienda, se enciende `clinica.hoja_de_los_imprimibles`.
     */
    public static function hojaUnicaHabilitada(): bool
    {
        return (bool) config('clinica.hoja_de_los_imprimibles', false);
    }

    /** El camino inverso de `deLaPreferencia`: qué opción de la pantalla corresponde a un modo. */
    public static function preferenciaDe(string $modo): ?string
    {
        return match ($modo) {
            self::COMPLETA => self::HOJA_VERTICAL,
            self::MITAD_SUPERIOR => self::HOJA_MEDIA_VERTICAL,
            self::HORIZONTAL => self::HOJA_HORIZONTAL,
            self::MITAD => self::HOJA_RECIPE,
            default => null,
        };
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
     * Las medidas con las que se mide y se reparte ese modo: la media hoja de siempre, el A4 vertical completo o su mitad
     * superior. La media hoja sola (`HORIZONTAL`) mide lo mismo que `MITAD`: lo único que cambia es cuántos entran por página.
     *
     * Es lo único que el distribuidor necesita saber del modo; el resto de la cuenta es la misma.
     */
    public static function medidas(string $modo): MedidasDeLaMitad
    {
        if ($modo === self::COMPLETA) {
            return MedidasDeLaMitad::deLaHojaCompleta();
        }

        return $modo === self::MITAD_SUPERIOR ? MedidasDeLaMitad::deLaMitadSuperior() : new MedidasDeLaMitad();
    }

    /**
     * Cuántos documentos entran en **una página** de ese modo: dos en la hoja horizontal compartida (`MITAD`) y uno solo en
     * las tres hojas que dejan el resto de la página en blanco (la completa, la mitad superior y la media hoja sola).
     */
    public static function porPagina(string $modo): int
    {
        return $modo === self::MITAD ? 2 : 1;
    }

    /**
     * ¿Ese modo usa una **página vertical** (A4 retrato)? La completa y la mitad superior sí; la media hoja compartida y la
     * media hoja sola no: las dos van en el A4 horizontal.
     */
    public static function esVertical(string $modo): bool
    {
        return in_array($modo, [self::COMPLETA, self::MITAD_SUPERIOR], true);
    }

    /**
     * Las clases CSS del `<article>` de una hoja de ese modo: la hoja completa y la mitad superior comparten la **página
     * vertical con nombre** (`recipe-hoja-completa`), y la mitad superior suma la clase que recorta el alto de la mitad.
     */
    public static function clasesDeLaHoja(string $modo): string
    {
        if ($modo === self::COMPLETA) {
            return ' recipe-hoja-completa';
        }

        return $modo === self::MITAD_SUPERIOR ? ' recipe-hoja-completa recipe-hoja-mitad-superior' : '';
    }
}
