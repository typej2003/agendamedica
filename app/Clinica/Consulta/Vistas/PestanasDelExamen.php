<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\TextosDelExamen;
use Closure;

/**
 * **Las solapas del examen** (WEB-6.5, el `tab_7` de `tabpage_examen` del escritorio): el catálogo de la fila de
 * solapas del ítem 4 del panel, y **el único lugar donde se agrega una solapa**.
 *
 * Las del escritorio son seis —*Examen Físico* (`tabpage_29`), *Examen Ginecológico* (`tabpage_30`),
 * *Colposcopía* (`tabpage_31`), *Citología* (`tabpage_33`), *Histeroscopía* (`tabpage_49`) y *Resultado de
 * exámenes* (`tabpage_48`)— y la web tiene cinco: la **evaluación física** (WEB-4.3) es una sola solapa que
 * cubre las dos primeras —el examen general y el ginecológico viven en la misma pantalla desde F4— y las otras
 * cuatro son las solapas de texto de `TextosDelExamen`, que traen su rótulo y su icono de ahí. Además, desde
 * O-22 (2026-10-17), la **galería de imágenes** de los estudios es una solapa más —al final y no de texto—, la
 * que vivía en la sección *Exámenes*, retirada del panel.
 *
 * ⚠️ **La clave de una solapa es su contrato**: es el `?pestana=`, el nombre de su parcial
 * (`clinica.consulta._evaluacion-<clave>`, ver `SeccionDeEvaluacionVista`) y el `name` del campo del texto. Un
 * desajuste entre la clave y el nombre del archivo es un **500** (trampa 114).
 */
final class PestanasDelExamen
{
    /** La evaluación física (WEB-4.3): el examen general y el ginecológico. */
    public const FISICO = 'fisico';

    /** Las imágenes de mamas (WEB-11.10): un lienzo por mama, por consulta. */
    public const MAMAS = 'mamas';

    /** El examen ginecológico (WEB-11.9): chip propio, como la solapa del escritorio. */
    public const GINECOLOGICO = 'ginecologico';

    /** Las mediciones (WEB-7.4.3a): talla, peso, perímetros, pulso y presión de pediatría (`percentiles_paciente`). */
    public const MEDICIONES = 'mediciones';

    /** El examen físico de pediatría (WEB-12.2): nueve cuadros por aparatos y el resumen (`examen_fisico`). */
    public const EXAMEN = 'examen';

    /** El cuestionario por aparatos de pediatría (WEB-12.2): once cuadros de texto (`cuestionario`). */
    public const CUESTIONARIO = 'cuestionario';

    /**
     * **Neumología pediátrica** (postgrado, 2026-10-19): la solapa de los tres informes del escritorio (R.X. de
     * tórax, espirometría y broncoscopía). Se ofrece **sólo si el médico tiene el postgrado**; `AlcanceDeLaEspecialidad`
     * la oculta en el resto de las especialidades y `ConsultaController` la suma a las ocultas cuando no lo tiene.
     */
    public const NEUMOLOGIA = 'neumologia';

    /** Las solapas propias de pediatría: se ofrecen sólo allí (`AlcanceDeLaEspecialidad`). */
    public const PEDIATRICAS = [self::MEDICIONES, self::EXAMEN, self::CUESTIONARIO, self::NEUMOLOGIA];

    /** La colposcopía, que es la primera solapa de texto (y por eso tiene constante propia). */
    public const COLPOSCOPIA = TextosDelExamen::COLPOSCOPIA;

    /**
     * **La galería de imágenes de los estudios** (O-22, 2026-10-17): una solapa más, al final, pero **no de
     * texto**: es el parcial `_imagenes` con «Esta consulta / Todas» y la subida, el que antes vivía en la
     * sección *Exámenes* (ítem 7 del panel, retirado). No es clínica de una especialidad: se ofrece en todas.
     */
    public const IMAGENES = 'imagenes';

    /** Las solapas que se ofrecen en **todas** las especialidades, además de las de cada una. */
    public const COMUNES = [self::IMAGENES];

    public const POR_DEFECTO = self::FISICO;

    private const ROTULOS = [
        self::FISICO => 'Examen físico',
        self::MEDICIONES => 'Mediciones',
        self::EXAMEN => 'Examen físico',
        self::CUESTIONARIO => 'Cuestionario',
        self::NEUMOLOGIA => 'Neumología',
        self::MAMAS => 'Imágenes de mamas',
        self::GINECOLOGICO => 'Examen ginecológico',
        self::IMAGENES => 'Imágenes',
    ];

    private const ICONOS = [
        self::FISICO => 'bi-clipboard2-pulse',
        self::MEDICIONES => 'bi-rulers',
        self::EXAMEN => 'bi-clipboard2-pulse',
        self::CUESTIONARIO => 'bi-chat-left-dots',
        self::NEUMOLOGIA => 'bi-lungs',
        self::MAMAS => 'bi-brush',
        self::GINECOLOGICO => 'bi-gender-female',
        self::IMAGENES => 'bi-images',
    ];

    /**
     * @param  Closure(string):string  $url  arma la dirección de una solapa (el controlador la resuelve)
     */
    /** @param  array<int,string>  $ocultas  las solapas que no se ofrecen en esta especialidad (WEB-12.1) */
    public function __construct(private Closure $url, private array $ocultas = [])
    {
    }

    /**
     * La clave de la solapa que se va a mostrar: la pedida si existe y, si no (o si no se pidió ninguna), la
     * que abre la sección.
     */
    public static function normalizar(?string $clave): string
    {
        $clave = $clave === null ? '' : trim($clave);

        return self::existe($clave) ? $clave : self::POR_DEFECTO;
    }

    public static function existe(?string $clave): bool
    {
        return $clave !== null && in_array($clave, self::claves(), true);
    }

    /** Las claves, en el orden del escritorio; la galería de imágenes va al final (O-22). */
    public static function claves(): array
    {
        return array_merge(
            [self::MEDICIONES, self::EXAMEN, self::CUESTIONARIO, self::NEUMOLOGIA, self::FISICO, self::MAMAS, self::GINECOLOGICO],
            TextosDelExamen::claves(),
            self::COMUNES,
        );
    }

    /**
     * Las solapas para el segmentado de `<x-ui.segmentado>`, que espera `texto`, `url` y `activa`.
     *
     * @return array<int,array{texto:string, url:string, activa:bool, clave:string, icono:string}>
     */
    public function opciones(string $activa): array
    {
        $claves = array_values(array_diff(self::claves(), $this->ocultas));

        return array_map(fn (string $clave) => [
            'texto' => self::rotulo($clave),
            'url' => ($this->url)($clave),
            'activa' => $clave === $activa,
            // La clave y el icono no los usa el control: los deja para quien tenga que distinguir la solapa.
            'clave' => $clave,
            'icono' => self::icono($clave),
        ], $claves);
    }

    public static function rotulo(string $clave): string
    {
        return self::ROTULOS[$clave] ?? TextosDelExamen::titulo($clave);
    }

    public static function icono(string $clave): string
    {
        return self::ICONOS[$clave] ?? TextosDelExamen::icono($clave);
    }

    /** ¿La solapa es una de las de texto? (Las cuatro comparten el ViewModel y el parcial.) */
    public static function esDeTexto(string $clave): bool
    {
        return TextosDelExamen::existe($clave);
    }
}
