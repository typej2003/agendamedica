<?php

namespace App\Clinica\Impresion\Vistas;

use App\Support\FechaClinica;
use Closure;

/**
 * **Los récipes de una cita atendida** (WEB-5.2): la lista para elegir cuál imprimir, que es lo que ve la
 * secretaría al apretar "Imprimir récipe" en la fila de una cita atendida cuando la consulta tiene **más de uno**
 * (varios récipes del paciente, o además el de la pareja).
 *
 * Es deliberadamente chica: **no muestra medicamentos ni indicaciones**, sólo cuántos tiene cada récipe. La
 * secretaría no ve la consulta ni la ficha clínica; lo único que llega a ver del contenido es lo que sale en la
 * hoja, y eso es la vista previa. Sin base de datos.
 */
final class RecipesDeLaCitaVista
{
    /**
     * @param  array<int,array{clave:string, titulo:string, resumen:string, fecha:?string, de_la_pareja:bool}>  $disponibles
     * @param  Closure  $urlImprimir  `fn(string $clave): string` — la vista previa de ese récipe (`todos` incluido)
     */
    public function __construct(
        private string $paciente,
        private string $fechaDeLaCita,
        private bool $atendida,
        private array $disponibles,
        private string $urlVolver,
        private Closure $urlImprimir,
        private OpcionesDeImpresionVista $dialogo,
    ) {
    }

    public function titulo(): string
    {
        return 'Récipes de ' . $this->paciente;
    }

    public function subtitulo(): string
    {
        return 'Cita del ' . FechaClinica::formato($this->fechaDeLaCita);
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function dialogo(): OpcionesDeImpresionVista
    {
        return $this->dialogo;
    }

    public function estaVacia(): bool
    {
        return $this->disponibles === [];
    }

    /** Por qué no hay nada para imprimir: la cita no se atendió, o se atendió y no recetó nada. */
    public function sinRecipes(): array
    {
        if (! $this->atendida) {
            return [
                'icono' => 'bi-hourglass-split',
                'titulo' => 'Esta cita todavía no fue atendida',
                'texto' => 'El récipe se imprime después de la consulta, cuando el médico ya lo hizo.',
            ];
        }

        return [
            'icono' => 'bi-prescription2',
            'titulo' => 'Esta consulta no tiene récipes',
            'texto' => 'Si el médico recetó algo, tiene que guardarlo en la consulta para que se pueda imprimir.',
        ];
    }

    /** ¿Se ofrece "Imprimir todos"? Sólo con más de un récipe. */
    public function puedeImprimirTodos(): bool
    {
        return count($this->disponibles) > 1;
    }

    public function urlImprimirTodos(): string
    {
        return ($this->urlImprimir)('todos');
    }

    /**
     * Los récipes de la cita, listos para pintar.
     *
     * @return array<int,array{titulo:string, resumen:string, fecha:?string, urlImprimir:string, icono:string}>
     */
    public function recipes(): array
    {
        return array_map(fn (array $recipe) => [
            'titulo' => $recipe['titulo'],
            'resumen' => $recipe['resumen'],
            'fecha' => $recipe['fecha'] === null ? null : FechaClinica::formato($recipe['fecha']),
            'urlImprimir' => ($this->urlImprimir)($recipe['clave']),
            'icono' => $recipe['de_la_pareja'] ? 'bi-people' : 'bi-prescription2',
        ], $this->disponibles);
    }
}
