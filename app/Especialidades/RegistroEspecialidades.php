<?php

namespace App\Especialidades;

use RuntimeException;

/**
 * Carga los manifiestos de `config/especialidades/*.php` y valida que no se contradigan
 * (PLAN-WEB.md, reglas R2 y R4).
 *
 * La validación es lo que hace usable la base compartida: si dos especialidades se declaran dueñas
 * de la misma tabla, eso **no se resuelve solo** — o la tabla es compartida y hay que declararla así
 * en ambos manifiestos, o alguna de las dos la está usando mal. Fallar temprano (un test, un comando)
 * es mejor que descubrirlo con datos de dos especialidades mezclados.
 */
final class RegistroEspecialidades
{
    /** @var array<string, ManifiestoEspecialidad> */
    private array $manifiestos = [];

    /** @var array<int, string> */
    private array $conflictos = [];

    public function __construct(?string $directorio = null)
    {
        $directorio = $directorio ?: config_path('especialidades');

        foreach (glob(rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $archivo) {
            $slug = basename($archivo, '.php');
            $datos = require $archivo;
            $this->manifiestos[$slug] = ManifiestoEspecialidad::desdeConfig($slug, (array) $datos);
        }

        $this->detectarConflictos();
    }

    /** @return array<string, ManifiestoEspecialidad> */
    public function todos(): array
    {
        return $this->manifiestos;
    }

    /** @return array<int, string> */
    public function slugs(): array
    {
        return array_keys($this->manifiestos);
    }

    public function porSlug(?string $slug): ?ManifiestoEspecialidad
    {
        return $slug !== null ? ($this->manifiestos[$slug] ?? null) : null;
    }

    /** Busca la especialidad de AppDDR a la que corresponde el nombre/código del legado de esa instalación. */
    public function porNombreLegado(?string $nombre, ?string $codigo = null): ?ManifiestoEspecialidad
    {
        foreach ($this->manifiestos as $manifiesto) {
            if ($manifiesto->reconoceLegado($nombre, $codigo)) {
                return $manifiesto;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function conflictos(): array
    {
        return $this->conflictos;
    }

    /** Lanza si hay manifiestos contradictorios. */
    public function validar(): void
    {
        if ($this->conflictos !== []) {
            throw new RuntimeException(
                "Manifiestos de especialidad contradictorios:\n - " . implode("\n - ", $this->conflictos)
            );
        }
    }

    private function detectarConflictos(): void
    {
        $duenios = [];

        foreach ($this->manifiestos as $slug => $manifiesto) {
            foreach ($manifiesto->propias as $tabla) {
                if (isset($duenios[$tabla])) {
                    $this->conflictos[] = "la tabla '{$tabla}' está declarada como propia en '{$duenios[$tabla]}' y en '{$slug}':"
                        . " si las dos la usan, declarala en 'compartidas' en ambos manifiestos";
                } else {
                    $duenios[$tabla] = $slug;
                }
            }

            foreach (array_intersect($manifiesto->propias, $manifiesto->compartidas) as $tabla) {
                $this->conflictos[] = "en '{$slug}' la tabla '{$tabla}' está como propia y como compartida a la vez";
            }

            foreach (array_intersect($manifiesto->propias, $manifiesto->locales) as $tabla) {
                $this->conflictos[] = "en '{$slug}' la tabla '{$tabla}' está como propia y como local (no migrable) a la vez";
            }
        }

        // Una tabla compartida en un manifiesto y propia en otro también es una contradicción.
        foreach ($this->manifiestos as $slug => $manifiesto) {
            foreach ($manifiesto->compartidas as $tabla) {
                if (isset($duenios[$tabla]) && $duenios[$tabla] !== $slug) {
                    $this->conflictos[] = "la tabla '{$tabla}' es propia de '{$duenios[$tabla]}' pero '{$slug}' la declara compartida";
                }
            }
        }
    }
}
