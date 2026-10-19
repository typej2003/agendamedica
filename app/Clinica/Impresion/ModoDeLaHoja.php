<?php

namespace App\Clinica\Impresion;

/**
 * **El modo de la hoja de cada documento** (O-26): la **única** regla que dice cómo se imprime un documento —en **media hoja
 * horizontal** (dos por A4), en **hoja completa vertical** (una por A4) o en la **mitad superior de un A4 vertical** (una por
 * página, con la mitad de abajo en blanco)—.
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
 *  - **Media hoja**: todo lo demás — récipes, constancia, reposo, referencia, informe médico, hospitalización, dieta,
 *    laboratorio y estudios.
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

    /** El modo de un documento, por su clave (`eco-pelvico`, `constancia`, `control-de-embarazo`…). */
    public static function de(string $clave): string
    {
        if (in_array($clave, self::HOJA_COMPLETA, true)) {
            return self::COMPLETA;
        }

        return in_array($clave, self::MITAD_SUPERIOR_DE_UN_A4, true) ? self::MITAD_SUPERIOR : self::MITAD;
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
     * superior.
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
     * Cuántos documentos entran en **una página** de ese modo: dos en la hoja horizontal (una en cada mitad) y uno solo en las
     * dos hojas verticales (la completa y la mitad superior, que deja el resto de la página en blanco).
     */
    public static function porPagina(string $modo): int
    {
        return $modo === self::MITAD ? 2 : 1;
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
