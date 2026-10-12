<?php

namespace App\Clinica\Verificacion;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * **Dibuja un código QR como SVG** en el servidor (WEB-5.6), con `bacon/bacon-qr-code` (PHP puro: no necesita
 * `gd` ni `imagick`). El SVG es vectorial, no depende de JavaScript ni de ningún CDN y sale nítido en el PDF del
 * navegador.
 *
 * ## Tamaño
 *
 * La hoja reserva **16 × 16 mm** para el QR. Una URL de verificación (`https://doctorisimo.app/verificar/` + 43 del
 * token = 77 bytes) con corrección de errores **M** cabe en la **versión 5** (37 × 37 módulos; la 5-M admite 84
 * bytes). Con **2 módulos de zona de silencio** por lado son 41 módulos en 16 mm: **0,39 mm por módulo**, por encima
 * del mínimo legible de un lector de teléfono (≈ 0,25 mm). Si una URL más larga pasa a la versión 6 (41 módulos,
 * 45 con silencio) el módulo baja a 0,36 mm, que sigue siendo legible. El SVG no lleva medidas fijas: la hoja lo
 * dimensiona por CSS (`.recipe-qr-codigo`).
 */
final class GeneradorDeQr
{
    /** Zona de silencio en módulos (el estándar pide 4; los lectores de teléfono funcionan bien con 2). */
    public const SILENCIO_EN_MODULOS = 2;

    /**
     * El SVG del QR de `$contenido`, listo para incrustar en una página (sin la declaración `<?xml?>`) y sin medidas
     * absolutas: ocupa el ancho y el alto que le dé su contenedor.
     */
    public function svg(string $contenido): string
    {
        $escritor = new Writer(new ImageRenderer(
            new RendererStyle(200, self::SILENCIO_EN_MODULOS),
            new SvgImageBackEnd(),
        ));

        $svg = $escritor->writeString($contenido, 'UTF-8', ErrorCorrectionLevel::M());

        // Fuera la declaración XML (la página es HTML) y las medidas en píxeles (las pone el CSS); el `viewBox` queda.
        $svg = preg_replace('/\A<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;
        $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg, 2) ?? $svg;

        return trim(str_replace('<svg ', '<svg shape-rendering="crispEdges" role="img" aria-label="Código QR de verificación del récipe" ', $svg));
    }
}
