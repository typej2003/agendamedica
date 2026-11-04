<?php

namespace App\Clinica\Catalogos\Vistas;

use Illuminate\Support\Collection;

/**
 * La tarjeta «Cargar el catálogo inicial» de las pantallas de catálogo del médico (WEB-12.8): cuántas filas del catálogo inicial el médico todavía no tiene y el botón
 * que abre el diálogo para elegir cuáles copiar. Nunca se agregan solas: las acepta el médico.
 */
final class NovedadesDeCatalogoVista
{
    /**
     * @param  Collection<int,object>  $novedades  las filas iniciales que el médico no tiene (ya leídas)
     * @param  array<int,string>  $otrasColumnas  columnas de la identidad que acompañan a la etiqueta para distinguir dos
     *                                           filas con el mismo nombre (la presentación de un medicamento)
     */
    public function __construct(
        private string $titulo,
        private string $singular,
        private string $etiqueta,
        private Collection $novedades,
        private string $urlCargar,
        private bool $femenino = false,
        private array $otrasColumnas = [],
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

    /** El título del diálogo que elige qué cargar («Catálogo inicial de diagnósticos»). */
    public function tituloDelDialogo(): string
    {
        return 'Catálogo inicial de ' . mb_strtolower($this->titulo);
    }

    /**
     * Las filas que el médico no tiene, para la lista del diálogo: `id` de la fila inicial y el texto con el que se
     * reconoce (la etiqueta y, si hace falta, la presentación u otra columna de la identidad).
     *
     * @return array<int,array{id:int, texto:string}>
     */
    public function items(): array
    {
        return $this->novedades->map(function ($fila) {
            $partes = [trim((string) $fila->{$this->etiqueta})];

            foreach ($this->otrasColumnas as $columna) {
                $valor = trim((string) ($fila->{$columna} ?? ''));

                if ($valor !== '' && $valor !== $partes[0]) {
                    $partes[] = $valor;
                }
            }

            return ['id' => (int) $fila->id, 'texto' => implode(' — ', $partes)];
        })->values()->all();
    }

    public function cuantos(): int
    {
        return $this->novedades->count();
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
