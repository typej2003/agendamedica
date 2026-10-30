<?php

namespace App\Clinica\Catalogos;

/**
 * **Los tipos de plantilla de texto** (`formato_print.tipo`, WEB-8.4): qué código es cada tipo y dónde se ofrece. Es la lista
 * del ABM del escritorio (`d_a_formatos.srd`, la columna `tipo` con sus valores) con el nombre en claro.
 *
 * Los tipos de `USADOS_EN_LA_WEB` **ya los consume la consulta de la web**: las secciones de texto —evolución (`EV`), informe
 * (`IM`), dietas (`DI`), enfermedad actual (`EA`), las solapas del examen (`CO`, `CI`, `HI`, `RE`), el tratamiento (`TR`), los
 * resúmenes del examen (`RF`, `EG`), el examen funcional de medicina interna (`EF`)— y **los siete estudios ecográficos del
 * ítem 5** (`UG`, `O1`, `O2`, `O3`, `ED`, `UA`, `UM`; revisión de Alexander, 2026-10-17). Ahí la plantilla se **copia** al campo
 * que se escribe, así que una plantilla nueva o editada se ofrece al instante (es la misma tabla) y editarla no cambia lo que
 * ya está escrito en las consultas. Los que quedan en `null` son los que el escritorio tiene y la web todavía **no ofrece** (el
 * eco de cuello `EM` y el eco renal `ER`, que no tienen sección en ginecología): se pueden mantener acá, con su nombre. Un
 * código que no está en la lista (el legado puede traer cualquiera) se muestra tal cual y no se pierde al guardar.
 *
 * ⚠️ Esta clase dice **qué existe**, no qué ve cada especialidad: qué tipos se ofrecen en el ABM lo decide
 * `PlantillasDeLaEspecialidad` (en pediatría no se ofrecen los ginecológicos, 2026-10-18).
 */
final class TiposDePlantillaDeTexto
{
    /** Los tipos que la consulta de la web ya ofrece. */
    public const USADOS_EN_LA_WEB = ['EV', 'IM', 'DI', 'EA', 'CO', 'CI', 'HI', 'TR', 'RE', 'RF', 'EG', 'EF', 'EX', 'IG', 'EE', 'UG', 'O1', 'O2', 'O3', 'ED', 'UA', 'UM'];

    /** `código => [nombre, dónde se usa]`. El orden es el de las listas de la pantalla. */
    private const TIPOS = [
        'EV' => ['Evolución', 'Se ofrece en la evolución de la consulta.'],
        'IM' => ['Informe médico', 'Se ofrece en el informe médico de la consulta.'],
        'DI' => ['Dietas / Recomendaciones', 'Se ofrece en las dietas y recomendaciones de la consulta.'],
        'EA' => ['Enfermedad actual', 'Se ofrece en la enfermedad actual de la consulta.'],
        'EG' => ['Examen ginecológico', 'Se ofrece en el resumen del examen ginecológico.'],
        'RF' => ['Resumen físico', 'Se ofrece en el resumen del examen físico.'],
        // El examen funcional de medicina interna (Alexander, 2026-10-29): su texto propio, con plantillas.
        'EF' => ['Examen funcional', 'Se ofrece en el examen funcional de medicina interna.'],
        'CI' => ['Citología', 'Se ofrece en la solapa de citología.'],
        'CO' => ['Colposcopía', 'Se ofrece en la solapa de colposcopía.'],
        'HI' => ['Histeroscopía', 'Se ofrece en la solapa de histeroscopía.'],
        'TR' => ['Tratamiento', 'Se ofrece en el tratamiento de la consulta.'],
        'RE' => ['Resultado de exámenes', 'Se ofrece en la solapa de resultado de exámenes.'],
        // Las tres de **Exámenes/Estudios**, el ítem 6 de la consulta pediátrica (2026-10-19).
        'EX' => ['Exámenes', 'Se ofrece en Exámenes/Estudios de la consulta pediátrica, en la solapa Exámenes.'],
        'IG' => ['Imagenología', 'Se ofrece en Exámenes/Estudios de la consulta pediátrica, en la solapa Imagenología.'],
        'EE' => ['Estudios exploratorios', 'Se ofrece en Exámenes/Estudios de la consulta pediátrica, en la solapa Estudios exploratorios.'],
        'UG' => ['Eco ginecológico', 'Se ofrece en el estudio ecográfico, en el eco ginecológico.'],
        'O1' => ['Obstétrico 1', 'Se ofrece en el estudio ecográfico, en el eco obstétrico del 1.er trimestre.'],
        'O2' => ['Obstétrico 2', 'Se ofrece en el estudio ecográfico, en el eco obstétrico del 2.º trimestre.'],
        'O3' => ['Obstétrico 3', 'Se ofrece en el estudio ecográfico, en el eco obstétrico del 3.er trimestre.'],
        'ED' => ['Eco Doppler perinatal', 'Se ofrece en el estudio ecográfico, en el eco Doppler perinatal.'],
        'UA' => ['Eco abdominal', 'Se ofrece en el estudio ecográfico, en el eco abdominal.'],
        'UM' => ['Eco mamario', 'Se ofrece en el estudio ecográfico, en el eco mamario.'],
        // El escritorio los tiene, pero en la web no hay pantalla que los ofrezca: no están en `USADOS_EN_LA_WEB`.
        'EM' => ['Eco de cuello', null],
        'ER' => ['Eco renal', null],
    ];

    /** @return array<string,string> `código => nombre` de todos los tipos conocidos, en el orden de la pantalla */
    public static function nombres(): array
    {
        return array_map(fn (array $tipo) => $tipo[0], self::TIPOS);
    }

    /** ¿El código es uno de los tipos conocidos? */
    public static function conocido(?string $codigo): bool
    {
        return $codigo !== null && isset(self::TIPOS[$codigo]);
    }

    /** El nombre en claro de un tipo; un código desconocido se muestra tal cual («Tipo XX»), y el vacío como «Sin tipo». */
    public static function nombre(?string $codigo): string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return 'Sin tipo';
        }

        return self::TIPOS[$codigo][0] ?? 'Tipo ' . $codigo;
    }

    /** ¿La consulta de la web ya ofrece las plantillas de este tipo? */
    public static function seUsaEnLaWeb(?string $codigo): bool
    {
        return in_array(trim((string) $codigo), self::USADOS_EN_LA_WEB, true);
    }

    /** Dónde se usa el tipo en la web, o `null` si todavía no se usa. */
    public static function dondeSeUsa(?string $codigo): ?string
    {
        return self::TIPOS[trim((string) $codigo)][1] ?? null;
    }
}
