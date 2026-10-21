<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\CopiarDeLaConsultaAnterior;
use App\Models\Consulta;

/**
 * **La casilla «Anterior»** de una sección de la consulta (WEB-11.4, casilla desde el pedido de Alexander del 2026-10-20): de dónde
 * trae, si está marcada y lo que la pantalla necesita para traer o sacar. La regla vive en `CopiarDeLaConsultaAnterior`.
 *
 * Tres formas, según cómo guarda la sección:
 *
 *  - `lista` (motivos, diagnósticos): un `POST` al instante. Marcada = todo lo de la anterior ya está en ésta (lo decide el servidor).
 *  - `texto` (enfermedad actual, tratamiento): pone el texto de la anterior en el campo y se guarda con el guardado de la sección.
 *  - `recipe` (el editor de récipes): pone los medicamentos del último récipe de la anterior en el récipe que se edita; se guarda
 *    con «Guardar el récipe».
 *
 * En `texto` y `recipe` la marca la decide el script al abrir (`anterior.js`): depende de lo que el editor tiene en ese momento.
 */
final class AnteriorVista
{
    public const LISTA = 'lista';
    public const TEXTO = 'texto';
    public const RECIPE = 'recipe';

    /**
     * @param  string  $que  la sección (`motivos`, `diagnosticos`, `enfermedad`, `tratamiento`, `recipes`): el id del control
     * @param  string  $nombre  cómo se nombra lo que se trae, en plural («motivos»): el `title` cuando no hay nada que traer
     * @param  array<int,string>  $coinciden  (lista) lo de ésta que coincide con la anterior: lo que desmarcar saca
     * @param  array<int,array<string,mixed>>  $items  (recipe) los medicamentos del récipe de la anterior
     */
    public function __construct(
        private string $tipo,
        private string $que,
        private string $nombre,
        private ?Consulta $desde,
        private bool $activo = false,
        private ?string $url = null,
        private array $coinciden = [],
        private ?string $texto = null,
        private array $items = [],
    ) {
    }

    /**
     * La casilla de la sección abierta, o `null` si la sección no la tiene o la consulta es la primera de la historia.
     */
    public static function deLaSeccion(?string $seccion, Consulta $consulta, CopiarDeLaConsultaAnterior $copiar): ?self
    {
        if (! in_array($seccion, [
            ConsultaShellVista::SECCION_MOTIVOS, ConsultaShellVista::SECCION_DIAGNOSTICO, ConsultaShellVista::SECCION_RECIPES,
            ConsultaShellVista::SECCION_ENFERMEDAD, ConsultaShellVista::SECCION_TRATAMIENTO,
        ], true) || ! $copiar->hayAnterior($consulta)) {
            return null;
        }

        $url = fn (string $que) => route('clinica.consulta.anterior', [$consulta->id, $que]);

        switch ($seccion) {
            case ConsultaShellVista::SECCION_MOTIVOS:
                $fuente = $copiar->fuenteDeMotivos($consulta);

                return self::deLista('motivos', 'motivos', $fuente, $url('motivos'));

            case ConsultaShellVista::SECCION_DIAGNOSTICO:
                $fuente = $copiar->fuenteDeDiagnosticos($consulta);

                return self::deLista('diagnosticos', 'diagnósticos', $fuente, $url('diagnosticos'));

            case ConsultaShellVista::SECCION_RECIPES:
                $recipe = $copiar->recipeAnterior($consulta);

                return new self(self::RECIPE, 'recipes', 'récipes', $recipe['desde'] ?? null, false, null, [], null, $recipe['items'] ?? []);

            default:
                $columna = $seccion === ConsultaShellVista::SECCION_ENFERMEDAD ? 'enfermedadactual' : 'laringe';
                $texto = $copiar->textoAnterior($consulta, $columna);

                return new self(
                    self::TEXTO, $seccion, $seccion === ConsultaShellVista::SECCION_ENFERMEDAD ? 'enfermedad actual' : 'tratamiento',
                    $texto['desde'] ?? null, false, null, [], $texto['texto'] ?? null,
                );
        }
    }

    /** @param  array{desde:?Consulta, anteriores:array<string,string>, actuales:array<int,string>}  $fuente */
    private static function deLista(string $que, string $nombre, array $fuente, string $url): self
    {
        $anteriores = $fuente['anteriores'];
        $actuales = array_values($fuente['actuales']);
        $faltan = array_diff(array_map('strval', array_keys($anteriores)), $actuales);
        $coinciden = array_values(array_map(
            fn (string $codigo) => $anteriores[$codigo],
            array_values(array_filter(array_map('strval', array_keys($anteriores)), fn (string $codigo) => in_array($codigo, $actuales, true))),
        ));

        return new self(
            self::LISTA, $que, $nombre,
            $anteriores === [] ? null : $fuente['desde'],
            $anteriores !== [] && $faltan === [],
            $url,
            $coinciden,
        );
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    public function id(): string
    {
        return 'anterior-' . $this->que;
    }

    /** ¿Hay algo que traer? Sin nada, la casilla se ve deshabilitada (la consulta sí tiene anteriores). */
    public function habilitado(): bool
    {
        return $this->desde !== null;
    }

    public function activo(): bool
    {
        return $this->activo;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /** La fecha de la consulta de donde se trae (`dd/mm/aaaa`), o su número si no tiene fecha. */
    public function deCual(): string
    {
        if ($this->desde === null) {
            return '';
        }

        return $this->desde->fecha
            ? 'del ' . $this->desde->fecha->format('d/m/Y')
            : 'N.º ' . (int) $this->desde->nroconsulta;
    }

    public function titulo(): string
    {
        return $this->habilitado()
            ? 'Traer de la consulta ' . $this->deCual()
            : 'Ninguna consulta anterior tiene ' . $this->nombre;
    }

    /** La pregunta antes de desmarcar (lista): sacar de ésta lo que coincide con la anterior. */
    public function confirmarQuitar(): string
    {
        $cuales = $this->coinciden === [] ? '' : ': ' . implode(', ', $this->coinciden);

        return '¿Quitar de esta consulta los ' . $this->nombre . ' que vienen de la consulta ' . $this->deCual() . $cuales . '?';
    }

    public function texto(): ?string
    {
        return $this->texto;
    }

    /** @return array<int,array<string,mixed>> */
    public function items(): array
    {
        return $this->items;
    }
}
