<?php

namespace App\Clinica\Consulta;

/**
 * **Los siete tipos de antecedente y cómo se llaman en cada especialidad** (WEB-12.3; ginecología, 2026-10-28).
 *
 * Los códigos `01`–`07` son los mismos en todo el legado: son las siete solapas del escritorio, en su orden
 * (Per., Fam., H.P., Obst., Gine., A.S., Otros). Lo que cambia por especialidad es el **nombre**, y por eso el
 * catálogo `tipo_antecedente` vive por `reg_medico`: la lectura prefiere las filas del propio médico y el de
 * cualquier otro sólo completa lo que le falte (`AntecedentesDelPaciente`), y el sembrado nunca pisa el nombre
 * que el médico ya tiene.
 *
 *  - **Pediatría** (`w_consulta_principal.srw:3069-3082`, `retrieve(historia, '01')` … `'07')`): PATOLÓGICOS,
 *    C.A., MADRE, PADRE, PERINATALES, ALIMENTACIÓN y D.P. (desarrollo psicomotor). *C.A.* se deja **literal**:
 *    no se sabe qué significa (pregunta 16 de `preguntas-para-alexander.md`).
 *  - **Ginecología y obstetricia** (2026-10-28): los nombres reales salen de la tabla `tipo_antecedente` de la
 *    base de ejemplo `Docs\DATAGI - datos de ejemplo en ASA 8.0.1.zip` (siete filas, sin `reg_medico`) y del
 *    volcado `Docs\MICONSULTAGI-legado\archivos sql\export_parte_14.sql:759-765`, que coincide: PERSONAL,
 *    FAMILIAR, PSICOBIOLOGICOS, OBSTETRICOS, GINECOLOGICOS, ACTIVIDAD SEXUAL y VARIOS. Van **sin tildes**, como
 *    en la fuente (`PSICOBIOLOGICOS`, `OBSTETRICOS`, `GINECOLOGICOS`).
 *
 * Las demás especialidades no tienen nombres propios en la fuente: `para()` devuelve `null` y **no se siembra
 * nada**; inventarlos sería peor que no tenerlos. Lo mismo vale para una especialidad sin catálogo conocido:
 * no se le presta el de otra.
 *
 * La **letra** `tipoantecedente` también sale de la fuente de ginecología (`P`, `F`, `S`, `O`, `G`, `A`, `V`);
 * pediatría no la trae y sus filas quedan con `NULL`, como hasta ahora.
 */
final class TiposDeAntecedente
{
    /** `codetipo => nombre`, en el orden de las solapas de ginecología. */
    public const GINECOLOGIA = [
        '01' => 'PERSONAL',
        '02' => 'FAMILIAR',
        '03' => 'PSICOBIOLOGICOS',
        '04' => 'OBSTETRICOS',
        '05' => 'GINECOLOGICOS',
        '06' => 'ACTIVIDAD SEXUAL',
        '07' => 'VARIOS',
    ];

    /** `codetipo => nombre`, en el orden de las solapas de pediatría. */
    public const PEDIATRIA = [
        '01' => 'PATOLÓGICOS',
        '02' => 'C.A.',
        '03' => 'MADRE',
        '04' => 'PADRE',
        '05' => 'PERINATALES',
        '06' => 'ALIMENTACIÓN',
        '07' => 'D.P.',
    ];

    /** El `tipoantecedente` de la fuente de ginecología (en pediatría se queda `NULL`). */
    public const LETRAS_DE_GINECOLOGIA = [
        '01' => 'P',
        '02' => 'F',
        '03' => 'S',
        '04' => 'O',
        '05' => 'G',
        '06' => 'A',
        '07' => 'V',
    ];

    /**
     * Los nombres para el ABM del catálogo inicial (`CatalogosIniciales`, que es una constante y no puede llamar
     * métodos): los códigos son los mismos y la fila puede ser de una u otra especialidad, así que se muestran los
     * dos (`PERSONAL / PATOLÓGICOS`).
     */
    public const ETIQUETAS_DEL_ADMINISTRADOR = [
        '01' => self::GINECOLOGIA['01'] . ' / ' . self::PEDIATRIA['01'],
        '02' => self::GINECOLOGIA['02'] . ' / ' . self::PEDIATRIA['02'],
        '03' => self::GINECOLOGIA['03'] . ' / ' . self::PEDIATRIA['03'],
        '04' => self::GINECOLOGIA['04'] . ' / ' . self::PEDIATRIA['04'],
        '05' => self::GINECOLOGIA['05'] . ' / ' . self::PEDIATRIA['05'],
        '06' => self::GINECOLOGIA['06'] . ' / ' . self::PEDIATRIA['06'],
        '07' => self::GINECOLOGIA['07'] . ' / ' . self::PEDIATRIA['07'],
    ];

    /**
     * Los siete nombres de la especialidad, o `null` si la fuente no los tiene (no se inventan).
     *
     * Sin especialidad en el contexto se toma ginecología: la instalación del legado que no la declara es la de
     * ginecología (la misma vara de `AlcanceDeLaEspecialidad::esGinecologia()`).
     *
     * @return array<string,string>|null
     */
    public static function para(?string $especialidad): ?array
    {
        return match (self::familia($especialidad)) {
            'ginecologia' => self::GINECOLOGIA,
            'pediatria' => self::PEDIATRIA,
            default => null,
        };
    }

    /**
     * La letra de `tipoantecedente` que la fuente trae para esa especialidad; `[]` si no la trae.
     *
     * @return array<string,string>
     */
    public static function letras(?string $especialidad): array
    {
        return self::familia($especialidad) === 'ginecologia' ? self::LETRAS_DE_GINECOLOGIA : [];
    }

    /**
     * La familia de la especialidad, con la misma vara que `AlcanceDeLaEspecialidad` (`str_contains`): así un slug
     * con sufijo sigue encontrando sus nombres. Sin especialidad es ginecología.
     */
    private static function familia(?string $especialidad): ?string
    {
        $slug = mb_strtolower(trim((string) $especialidad));

        if ($slug === '' || str_contains($slug, 'ginecolog')) {
            return 'ginecologia';
        }

        if (str_contains($slug, 'pediatr')) {
            return 'pediatria';
        }

        return null;
    }
}
