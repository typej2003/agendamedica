<?php

namespace App\Clinica\Impresion;

/**
 * **El modo de la hoja de cada documento** (O-26): la **única** regla que dice si un documento se imprime en **media hoja
 * horizontal** (dos por A4) o en **hoja completa vertical** (una por A4).
 *
 * Vive acá, al lado de las medidas (`MedidasDeLaMitad`), porque el motor de documentos no repite la decisión por documento: un
 * documento **declara** su modo —en el mapa de abajo, con su clave— y `HojasDeDocumentoVista` le pide a esta clase las medidas
 * y cuántos documentos entran por página. El reparto del texto sigue siendo el de `DistribuidorDeDocumento`, que sólo cambia
 * los milímetros con los que mide.
 *
 * ## Qué va en cada modo (decisión del usuario, 2026-10-17)
 *
 *  - **Hoja completa vertical**: el informe del **control de embarazo** y los informes de **los siete ecos** (pélvico,
 *    obstétrico del 1.º, del 2.º y del 3.er trimestre, Doppler, mamario, abdominal y cardiograma fetal).
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

    /**
     * Los documentos que declaran **hoja completa vertical**. Los que no están acá son de media hoja: así un documento nuevo
     * no tiene que decir nada (el caso de siempre) y sólo suma su línea si va entero.
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

    /** El modo de un documento, por su clave (`eco-pelvico`, `constancia`, `control-de-embarazo`…). */
    public static function de(string $clave): string
    {
        return in_array($clave, self::HOJA_COMPLETA, true) ? self::COMPLETA : self::MITAD;
    }

    /** ¿Ese documento va en hoja completa vertical? */
    public static function esCompleta(string $clave): bool
    {
        return self::de($clave) === self::COMPLETA;
    }

    /**
     * Las medidas con las que se mide y se reparte ese modo: la media hoja de siempre o el A4 vertical completo.
     *
     * Es lo único que el distribuidor necesita saber del modo; el resto de la cuenta es la misma.
     */
    public static function medidas(string $modo): MedidasDeLaMitad
    {
        return $modo === self::COMPLETA ? MedidasDeLaMitad::deLaHojaCompleta() : new MedidasDeLaMitad();
    }

    /**
     * Cuántos documentos entran en **una página** de ese modo: dos en la hoja horizontal (una en cada mitad) y uno solo en la
     * hoja completa (que es entera para él).
     */
    public static function porPagina(string $modo): int
    {
        return $modo === self::COMPLETA ? 1 : 2;
    }
}
