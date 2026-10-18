<?php

namespace App\Clinica\Tours\Vistas;

use App\Clinica\Tours\ToursDisponibles;

/**
 * El **tour de una pantalla** para el armazón (`clinica/layout`): los pasos ya filtrados por rol, si el
 * usuario ya lo vio y a dónde avisar cuando lo termine o lo salte.
 *
 * No consulta nada: el compositor `TourDeLaPantallaComposer` le pasa todo armado. La vista
 * `clinica/_tour` sólo recorre los pasos y `public/js/clinica/tour.js` los muestra.
 */
final class TourVista
{
    /**
     * @param  array<int,array{selector:string, titulo:string, texto:string}>  $pasos
     */
    public function __construct(
        private string $pantalla,
        private array $pasos,
        private bool $yaVisto,
        private string $urlMarcarVisto,
    ) {
    }

    public function pantalla(): string
    {
        return $this->pantalla;
    }

    /** @return array<int,array{selector:string, titulo:string, texto:string}> */
    public function pasos(): array
    {
        return $this->pasos;
    }

    public function hayPasos(): bool
    {
        return $this->pasos !== [];
    }

    /** El tour se abre solo la primera vez; después sólo con el botón de reabrir. */
    public function debeMostrarse(): bool
    {
        return ! $this->yaVisto;
    }

    public function yaVisto(): bool
    {
        return $this->yaVisto;
    }

    public function urlMarcarVisto(): string
    {
        return $this->urlMarcarVisto;
    }

    /**
     * ¿El botón de reabrir va **dentro del encabezado de la pantalla** en vez de en su propia fila?
     *
     * Sólo en la consulta (O-31, 2026-10-17): su encabezado es una sola fila de ~49 px que se ganó el alto
     * de la barra de contexto, y la ayuda no puede gastarlo con un renglón propio. Ahí la vista de la
     * consulta incluye `clinica/_tour-boton` junto a Guardar y Terminar, y `clinica/_tour` no pinta la fila.
     * En las demás pantallas el botón sigue arriba del encabezado, como siempre.
     */
    public function enElEncabezado(): bool
    {
        return $this->pantalla === ToursDisponibles::CONSULTA;
    }
}
