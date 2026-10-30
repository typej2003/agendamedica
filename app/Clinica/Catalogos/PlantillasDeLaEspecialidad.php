<?php

namespace App\Clinica\Catalogos;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;

/**
 * **Qué tipos de plantilla de texto le tocan a cada especialidad** (revisión del usuario, 2026-10-18).
 *
 * Las plantillas son **una sola tabla** (`formato_print`) para todas las secciones y todas las especialidades, y el tipo es lo
 * que dice a qué pantalla pertenecen. Antes el ABM las ofrecía todas a todo el mundo: en pediatría se veían las de
 * colposcopía, citología, histeroscopía y los siete ecos ginecológicos y obstétricos, que esa especialidad **no tiene**.
 *
 * La decisión de qué sobra **no está acá**: la toma `AlcanceDeLaEspecialidad` (el único lugar donde se decide qué parte de la
 * consulta aplica a cada especialidad). Esta clase sólo traduce esa lista a los nombres del catálogo y responde las preguntas
 * que necesitan el ABM y su diálogo.
 *
 * ⚠️ **Un tipo que no está en el catálogo no se oculta nunca**: el escritorio puede traer cualquier código y esconderlo
 * borraría datos del médico que la web no sabe leer. Lo que se oculta son los tipos **conocidos** que no son de la
 * especialidad.
 */
final class PlantillasDeLaEspecialidad
{
    /**
     * Los tipos que la especialidad **sí** ofrece, en el orden del catálogo (`código => nombre`).
     *
     * @return array<string,string>
     */
    public static function nombres(?string $especialidad): array
    {
        $ocultos = self::ocultos($especialidad);

        return array_filter(
            TiposDePlantillaDeTexto::nombres(),
            fn (string $codigo) => ! in_array($codigo, $ocultos, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<int,string> los códigos que la especialidad ofrece */
    public static function codigos(?string $especialidad): array
    {
        return array_keys(self::nombres($especialidad));
    }

    /** ¿Este tipo es de otra especialidad (y por lo tanto no se ofrece en el ABM de ésta)? */
    public static function estaOculto(?string $codigo, ?string $especialidad): bool
    {
        return in_array(trim((string) $codigo), self::ocultos($especialidad), true);
    }

    /**
     * Los tipos que no se ofrecen en la especialidad: los de otra especialidad, los de las pantallas que ésta no tiene y
     * el **examen funcional** (`EF`), que es sólo de medicina interna (2026-10-29). Ginecología ya no ofrece exactamente
     * lo mismo que antes: suma `EF` a los tres de pediatría.
     *
     * @return array<int,string>
     */
    public static function ocultos(?string $especialidad): array
    {
        return AlcanceDeLaEspecialidad::de($especialidad)->tiposDePlantillaOcultos();
    }
}
