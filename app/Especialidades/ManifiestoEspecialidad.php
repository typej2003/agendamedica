<?php

namespace App\Especialidades;

use InvalidArgumentException;

/**
 * Manifiesto de una especialidad (PLAN-WEB.md, regla R2). Es un objeto de valor: se arma desde
 * `config/especialidades/<slug>.php` y no toca la base.
 *
 * Declara, sin código especial por especialidad:
 *  - cómo la llama el legado (`codigos_legado` / `nombres_legado`), que varía por instalación;
 *  - sus módulos clínicos (los slugs del catálogo `modulos_clinicos`);
 *  - sus tablas: `propias` (del módulo), `compartidas` (las usa más de una especialidad) y
 *    `locales` (de la instalación, no se migran: usuarios del escritorio, licencias, respaldos);
 *  - `columnas_nuevas`: columnas nullable agregadas a tablas legadas;
 *  - `pantallas`: mapa módulo => ventanas PowerBuilder, que es la semilla de la matriz de paridad.
 */
final class ManifiestoEspecialidad
{
    public function __construct(
        public string $slug,
        public string $nombre,
        public ?string $codigo = null,
        public ?string $specialtySlug = null,
        public array $codigosLegado = [],
        public array $nombresLegado = [],
        public array $modulos = [],
        public array $propias = [],
        public array $compartidas = [],
        public array $locales = [],
        public array $columnasNuevas = [],
        public array $pantallas = []
    ) {
    }

    public static function desdeConfig(string $slug, array $datos): self
    {
        if (empty($datos['nombre'])) {
            throw new InvalidArgumentException("El manifiesto '{$slug}' no declara 'nombre'.");
        }

        return new self(
            slug: $slug,
            nombre: (string) $datos['nombre'],
            codigo: isset($datos['codigo']) ? (string) $datos['codigo'] : null,
            specialtySlug: isset($datos['specialty_slug']) ? (string) $datos['specialty_slug'] : $slug,
            codigosLegado: array_map('strval', (array) ($datos['codigos_legado'] ?? [])),
            nombresLegado: array_values((array) ($datos['nombres_legado'] ?? [])),
            modulos: array_values((array) ($datos['modulos'] ?? [])),
            propias: array_values((array) ($datos['propias'] ?? [])),
            compartidas: array_values((array) ($datos['compartidas'] ?? [])),
            locales: array_values((array) ($datos['locales'] ?? [])),
            columnasNuevas: (array) ($datos['columnas_nuevas'] ?? []),
            pantallas: (array) ($datos['pantallas'] ?? [])
        );
    }

    /**
     * Normaliza un nombre de especialidad del legado para compararlo: el catálogo real trae
     * "GINECOLOGIA Y OBSTETRICIA." y "TRAUMATOLOGO" conviviendo con "TRAUMATOLOGIA", así que se
     * ignoran acentos, puntos, paréntesis y espacios de más.
     */
    public static function normalizar(string $texto): string
    {
        // mb_strtoupper, no strtoupper: la versión de un byte no toca las vocales acentuadas
        // ('ginecología' quedaba con la 'í' minúscula y no coincidía con 'GINECOLOGIA').
        $texto = mb_strtoupper(trim($texto), 'UTF-8');
        $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $texto = (string) preg_replace('/[^A-Z0-9 ]+/', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /** ¿Esta especialidad reconoce el nombre y/o el `codeespecial` con que la llama esa instalación? */
    public function reconoceLegado(?string $nombre, ?string $codigo = null): bool
    {
        if ($codigo !== null && $codigo !== '' && in_array(trim($codigo), $this->codigosLegado, true)) {
            return true;
        }
        if ($nombre === null || trim($nombre) === '') {
            return false;
        }

        $buscado = self::normalizar($nombre);
        foreach ($this->nombresLegado as $candidato) {
            if (self::normalizar((string) $candidato) === $buscado) {
                return true;
            }
        }

        return false;
    }

    /** Todas las tablas que declara el manifiesto, sin repetir. */
    public function tablasDeclaradas(): array
    {
        return array_values(array_unique(array_merge($this->propias, $this->compartidas, $this->locales)));
    }
}
