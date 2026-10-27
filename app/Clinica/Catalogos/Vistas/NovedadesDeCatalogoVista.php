<?php

namespace App\Clinica\Catalogos\Vistas;

use Illuminate\Support\Collection;

/**
 * La tarjeta «Cargar el catálogo inicial» de las pantallas de catálogo del médico (WEB-12.8): cuántas filas del catálogo inicial el médico todavía no tiene y el botón
 * que las copia. Nunca se agregan solas: las acepta con el botón. Sólo se pinta si hay novedades.
 */
final class NovedadesDeCatalogoVista
{
    /** @param  Collection<int,object>  $novedades  las filas iniciales que el médico no tiene (ya leídas) */
    public function __construct(
        private string $titulo,
        private string $singular,
        private string $etiqueta,
        private Collection $novedades,
        private string $urlCargar,
        private bool $femenino = false,
    ) {
    }

    public function hay(): bool
    {
        return $this->novedades->isNotEmpty();
    }

    public function urlCargar(): string
    {
        return $this->urlCargar;
    }

    public function texto(): string
    {
        $total = $this->novedades->count();
        $nombres = $this->novedades->take(3)->map(fn ($f) => '«' . trim((string) $f->{$this->etiqueta}) . '»')->implode(', ');

        $nuevo = $this->femenino ? 'nueva' : 'nuevo';

        return ($total === 1 ? 'Hay 1 ' . $this->singular . ' ' . $nuevo : 'Hay ' . $total . ' ' . mb_strtolower($this->titulo) . ' ' . $nuevo . 's')
            . ' en el catálogo inicial que todavía no tienes: ' . $nombres . ($total > 3 ? ' y ' . ($total - 3) . ' más' : '')
            . '. Lo que ya tienes no se toca.';
    }
}
