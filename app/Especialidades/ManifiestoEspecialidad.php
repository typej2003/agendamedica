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
 *  - sus **subespecialidades** (los postgrados de esa especialidad), con los módulos del menú y las secciones
 *    de la consulta que enciende cada una (2026-10-19);
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
        /** @var array<string, array{slug:string, nombre:string, codigo:?string, orden:int, modulos:array<int,string>, secciones:array<int,string>}> */
        public array $subespecialidades = [],
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
            subespecialidades: self::normalizarSubespecialidades($slug, $datos['subespecialidades'] ?? []),
            propias: array_values((array) ($datos['propias'] ?? [])),
            compartidas: array_values((array) ($datos['compartidas'] ?? [])),
            locales: array_values((array) ($datos['locales'] ?? [])),
            columnasNuevas: (array) ($datos['columnas_nuevas'] ?? []),
            pantallas: (array) ($datos['pantallas'] ?? [])
        );
    }

    /**
     * **Las subespecialidades (postgrados) de la especialidad** tal como las declara el manifiesto
     * (2026-10-19), normalizadas a una lista con la clave `slug`.
     *
     * La forma corta es `'slug' => 'Nombre'`; la larga agrega lo que enciende:
     *
     * ```php
     * 'subespecialidades' => [
     *     'nefrologia-pediatrica' => [
     *         'nombre'    => 'Nefrología pediátrica',
     *         'codigo'    => '…',            // opcional: el `codeespecial` del legado, si la tiene
     *         'orden'     => 10,
     *         'modulos'   => ['…'],          // módulos del MENÚ que enciende (tabla `subespecialidad_modulo`)
     *         'secciones' => ['indicadores'],// secciones de la CONSULTA que agrega al catálogo de pediatría
     *     ],
     * ],
     * ```
     *
     * Las `secciones` son claves del catálogo de `SeccionesDeLaConsulta`: la consulta de la especialidad
     * arma su lista y el postgrado inserta las suyas en el lugar del legado.
     *
     * @param  array<string, mixed>  $declaradas
     * @return array<string, array{slug:string, nombre:string, codigo:?string, orden:int, modulos:array<int,string>, secciones:array<int,string>}>
     */
    public static function normalizarSubespecialidades(string $especialidad, array $declaradas): array
    {
        $salida = [];
        $posicion = 0;

        foreach ($declaradas as $clave => $datos) {
            // Forma corta: 'nefrologia-pediatrica' => 'Nefrología pediátrica'.
            $datos = is_string($datos) ? ['nombre' => $datos] : (array) $datos;

            $slug = trim((string) ($datos['slug'] ?? $clave));
            if ($slug === '' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
                throw new InvalidArgumentException(
                    "El manifiesto '{$especialidad}' declara la subespecialidad '{$slug}', que no es un slug válido"
                    . ' (minúsculas, números y guiones).'
                );
            }

            $nombre = trim((string) ($datos['nombre'] ?? ''));
            if ($nombre === '') {
                throw new InvalidArgumentException(
                    "La subespecialidad '{$slug}' del manifiesto '{$especialidad}' no declara 'nombre'."
                );
            }

            $salida[$slug] = [
                'slug'      => $slug,
                'nombre'    => $nombre,
                'codigo'    => isset($datos['codigo']) ? (string) $datos['codigo'] : null,
                'orden'     => (int) ($datos['orden'] ?? (($posicion + 1) * 10)),
                'modulos'   => array_values(array_map('strval', (array) ($datos['modulos'] ?? []))),
                'secciones' => array_values(array_map('strval', (array) ($datos['secciones'] ?? []))),
            ];

            $posicion++;
        }

        return $salida;
    }

    /** Una subespecialidad declarada por su slug, o `null`. */
    public function subespecialidad(string $slug): ?array
    {
        return $this->subespecialidades[$slug] ?? null;
    }

    /** Los slugs de todas las subespecialidades declaradas. */
    public function slugsDeSubespecialidades(): array
    {
        return array_keys($this->subespecialidades);
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
