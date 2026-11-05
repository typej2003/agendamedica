<?php

namespace App\Clinica\Impresion;

/**
 * **La hoja de cada imprimible** (2026-11-05): la elección que el médico hace **documento por documento** en la vista previa
 * de la impresión, con el botón de configuración que está al lado de «Imprimir».
 *
 * Son **tres** formas y no cuatro: el A4 horizontal entero no se usa (nadie lo pide), y el «estilo récipe» —dos documentos
 * por hoja— era de la hoja única y se retiró con ella.
 *
 *  - `COMPLETA` — hoja completa: un A4 vertical entero. Si el texto no entra, sigue en **otra hoja igual**.
 *  - `MEDIA_VERTICAL` — media hoja vertical: la mitad de arriba de un A4 vertical. La página del PDF mide **la mitad**
 *    (A5 acostada, 210 × 148,5 mm), así que no queda el espacio en blanco de abajo; si el texto no entra, sigue en **otra
 *    media hoja vertical**. El doctor recorta la hoja antes de ponerla en la impresora.
 *  - `MEDIA_HORIZONTAL` — media hoja horizontal: la mitad izquierda de un A4 acostado. La página del PDF mide la mitad
 *    (A5 vertical, 148,5 × 210 mm) y **nunca** sale la página completa; si el texto no entra, la siguiente hoja es **otra
 *    mitad izquierda**.
 *
 * Qué documentos la aceptan: los **documentos de texto** —las nueve solicitudes y el resumen médico—, que son los que
 * imprime el motor de mitades (`ModoDeLaHoja`). Los **récipes** siguen siempre en media hoja horizontal, y los ecos, el
 * control de embarazo, la colposcopía, la histeroscopía y el preoperatorio tienen su hoja fija (su informe es un formulario
 * que ocupa la página entera).
 *
 * La preferencia se guarda en `recipe_formatos.hojas_de_los_imprimibles`, bajo el registro del **doctor que firma** (igual
 * que las opciones del récipe: la secretaría también la cambia y el médico ve lo mismo). Sólo se guardan las claves que el
 * médico eligió: una clave que falta es «la hoja de siempre» de ese documento.
 *
 * Es una clase **sin estado y sin base de datos**.
 */
final class HojaDelImprimible
{
    /** Las tres opciones, en el orden en que se ofrecen. */
    public const COMPLETA = 'completa';

    public const MEDIA_VERTICAL = 'media-vertical';

    public const MEDIA_HORIZONTAL = 'media-horizontal';

    /** @var array<int,string> */
    public const OPCIONES = [self::COMPLETA, self::MEDIA_VERTICAL, self::MEDIA_HORIZONTAL];

    /**
     * Los imprimibles que aceptan la elección: las nueve solicitudes (la orden de laboratorio de la pareja comparte la hoja
     * con la de la paciente) y el resumen médico.
     *
     * @var array<int,string>
     */
    public const CLAVES = [
        DocumentoImpreso::CONSTANCIA,
        DocumentoImpreso::REPOSO,
        DocumentoImpreso::REFERENCIA,
        DocumentoImpreso::INFORME,
        DocumentoImpreso::HOSPITALIZACION,
        DocumentoImpreso::DIETA,
        DocumentoImpreso::LABORATORIO,
        DocumentoImpreso::ESTUDIOS,
        DocumentoImpreso::RESUMEN_MEDICO,
    ];

    /** El título de cada opción, para el diálogo. */
    private const TITULOS = [
        self::COMPLETA => 'Hoja completa',
        self::MEDIA_VERTICAL => 'Media hoja vertical',
        self::MEDIA_HORIZONTAL => 'Media hoja horizontal',
    ];

    /** Qué dice de cada opción el diálogo de la vista previa. */
    private const DESCRIPCIONES = [
        self::COMPLETA => 'Una hoja A4 entera. Si el texto no entra, sigue en otra hoja igual.',
        self::MEDIA_VERTICAL => 'La mitad de arriba de la hoja. Si el texto no entra, sigue en otra media hoja.',
        self::MEDIA_HORIZONTAL => 'La mitad izquierda de la hoja acostada. Si el texto no entra, sigue en otra media hoja.',
    ];

    /**
     * ¿Ese documento entra en la elección? La clave puede ser la de la solicitud (`referencia-3`) o su tipo (`referencia`):
     * las dos se llevan a la clave de la configuración.
     */
    public static function acepta(string $clave): bool
    {
        return in_array(self::claveDe($clave), self::CLAVES, true);
    }

    /**
     * La clave con la que se guarda un documento: la orden de laboratorio **de la pareja** comparte la hoja con la orden de
     * la paciente (`laboratorio-pareja` → `laboratorio`); todo lo demás es su propia clave.
     */
    public static function claveDe(string $clave): string
    {
        return $clave === DocumentoImpreso::LABORATORIO_PAREJA ? DocumentoImpreso::LABORATORIO : $clave;
    }

    /** El título de una opción (`Hoja completa`, `Media hoja vertical`…). */
    public static function titulo(string $opcion): string
    {
        return self::TITULOS[$opcion] ?? self::TITULOS[self::COMPLETA];
    }

    /** La línea que explica una opción en el diálogo. */
    public static function descripcion(string $opcion): string
    {
        return self::DESCRIPCIONES[$opcion] ?? self::DESCRIPCIONES[self::COMPLETA];
    }

    /**
     * Las opciones del diálogo, con la que está elegida. Las arma el ViewModel; acá viven los textos.
     *
     * @return array<int,array{valor:string, titulo:string, descripcion:string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (string $opcion) => [
            'valor' => $opcion,
            'titulo' => self::titulo($opcion),
            'descripcion' => self::descripcion($opcion),
        ], self::OPCIONES);
    }

    /**
     * La hoja de un documento cuando el médico no eligió nada: la de siempre (`ModoDeLaHoja::de()`). El
     * `?pagina=grande|pequena` histórico de las solicitudes manda sobre lo guardado, como siempre.
     */
    public static function opcionPorDefecto(string $clave, ?string $pagina = null): string
    {
        $clave = self::claveDe($clave);

        if ($pagina !== null && ModoDeLaHoja::seEligeLaPagina($clave)) {
            return ModoDeLaHoja::pagina($pagina) === ModoDeLaHoja::PAGINA_PEQUENA ? self::MEDIA_VERTICAL : self::COMPLETA;
        }

        return self::opcionDe(ModoDeLaHoja::de($clave));
    }

    /** Qué opción corresponde a un modo del motor (`completa`, `mitad`, `mitad-superior`, `horizontal`, `media-vertical`). */
    public static function opcionDe(string $modo): string
    {
        return match ($modo) {
            ModoDeLaHoja::COMPLETA => self::COMPLETA,
            ModoDeLaHoja::MITAD_SUPERIOR, ModoDeLaHoja::MEDIA_VERTICAL => self::MEDIA_VERTICAL,
            default => self::MEDIA_HORIZONTAL,
        };
    }

    /**
     * La hoja **efectiva** de un documento: la que el médico guardó o, si no hay, la de siempre. El `?pagina=` de una
     * impresión concreta manda sobre lo guardado (es el enlace histórico del escritorio).
     *
     * @param  array<string,string>|null  $guardadas  el mapa de `recipe_formatos.hojas_de_los_imprimibles`
     */
    public static function actual(?array $guardadas, string $clave, ?string $pagina = null): string
    {
        $clave = self::claveDe($clave);

        if (! in_array($clave, self::CLAVES, true)) {
            return self::COMPLETA;
        }

        if ($pagina !== null && ModoDeLaHoja::seEligeLaPagina($clave)) {
            return self::opcionPorDefecto($clave, $pagina);
        }

        return self::normalizar($guardadas)[$clave] ?? self::opcionPorDefecto($clave);
    }

    /** El modo del motor (`ModoDeLaHoja`) de una opción de esta clase. */
    public static function modo(string $opcion): string
    {
        return match ($opcion) {
            self::MEDIA_VERTICAL => ModoDeLaHoja::MEDIA_VERTICAL,
            self::MEDIA_HORIZONTAL => ModoDeLaHoja::HORIZONTAL,
            default => ModoDeLaHoja::COMPLETA,
        };
    }

    /**
     * El modo con el que se imprime un documento según lo guardado, o `null` si el documento no acepta la elección (los
     * récipes, los ecos, la colposcopía, el preoperatorio…).
     *
     * @param  array<string,string>|null  $guardadas
     */
    public static function modoDe(?array $guardadas, string $clave, ?string $pagina = null): ?string
    {
        return self::acepta($clave) ? self::modo(self::actual($guardadas, $clave, $pagina)) : null;
    }

    /**
     * Deja sólo lo que esta clase entiende: claves de documentos que aceptan la elección y valores de las tres opciones. Una
     * fila vieja o un texto raro no rompen la impresión: esa clave se descarta y el documento sale como siempre.
     *
     * @param  array<string,mixed>|null  $mapa
     * @return array<string,string>
     */
    public static function normalizar(?array $mapa): array
    {
        $limpio = [];

        foreach ((array) $mapa as $clave => $opcion) {
            $clave = self::claveDe((string) $clave);

            if (in_array($clave, self::CLAVES, true) && in_array($opcion, self::OPCIONES, true)) {
                $limpio[$clave] = $opcion;
            }
        }

        return $limpio;
    }
}
