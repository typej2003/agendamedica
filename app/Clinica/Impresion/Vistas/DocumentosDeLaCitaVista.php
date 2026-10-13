<?php

namespace App\Clinica\Impresion\Vistas;

use App\Support\FechaClinica;
use Closure;

/**
 * **Los documentos de una cita atendida** (WEB-5.2, ampliado en WEB-5.3a): la lista para elegir qué imprimir, que es lo que
 * ve la secretaría al apretar «Imprimir documentos» en la fila de una cita atendida. Lleva **todo lo imprimible de la
 * consulta**: los récipes y las solicitudes (constancia, reposo, referencias e informe).
 *
 * Es deliberadamente chica: **no muestra medicamentos, indicaciones, diagnósticos ni el texto de nada**, sólo qué documento
 * es y un resumen que ya sale en la hoja (cuántos medicamentos, cuántos días, a qué médico se refiere). La secretaría no ve la
 * consulta ni la ficha clínica; lo único que llega a ver del contenido es lo que sale en la hoja, y eso es la vista previa.
 * Sólo **imprime**: nada de lo que acá aparece se crea ni se edita.
 *
 * **Extensible**: hospitalización, dietas, laboratorio y estudios (WEB-5.3b, WEB-5.4) llegan como más filas de
 * `$disponibles`; esta clase y su pantalla no cambian. Sin base de datos.
 */
final class DocumentosDeLaCitaVista
{
    /**
     * @param  array<int,array{clave:string, tipo:string, titulo:string, resumen:string, fecha:?string, icono:string, de_la_pareja:bool}>  $disponibles
     * @param  Closure  $urlImprimir  `fn(string $clave): string` — la vista previa de ese documento (`todos` incluido)
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
        return 'Documentos de ' . $this->paciente;
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

    /** Por qué no hay nada para imprimir: la cita no se atendió, o se atendió y no dejó ningún documento. */
    public function sinDocumentos(): array
    {
        if (! $this->atendida) {
            return [
                'icono' => 'bi-hourglass-split',
                'titulo' => 'Esta cita todavía no fue atendida',
                'texto' => 'Los documentos se imprimen después de la consulta, cuando el médico ya los hizo.',
            ];
        }

        return [
            'icono' => 'bi-file-earmark-medical',
            'titulo' => 'Esta consulta no tiene documentos para imprimir',
            'texto' => 'Si el médico recetó algo o hizo una constancia, un reposo, una referencia o un informe, tiene que guardarlo en la consulta para que se pueda imprimir.',
        ];
    }

    /** ¿Se ofrece «Imprimir todos»? Sólo con más de un documento. */
    public function puedeImprimirTodos(): bool
    {
        return count($this->disponibles) > 1;
    }

    public function urlImprimirTodos(): string
    {
        return ($this->urlImprimir)('todos');
    }

    /** ¿Entre lo que se imprime hay récipes? Entonces imprimir pasa antes por las opciones de la hoja (modo y nombre del medicamento). */
    public function hayRecipes(): bool
    {
        return $this->recipes() !== [];
    }

    /**
     * Los documentos de la cita, listos para pintar. Los récipes abren el diálogo de las opciones de la hoja; los demás
     * van directo a su vista previa.
     *
     * @return array<int,array{titulo:string, resumen:string, fecha:?string, urlImprimir:string, icono:string, conOpciones:bool}>
     */
    public function documentos(): array
    {
        return array_map(fn (array $documento) => [
            'titulo' => $documento['titulo'],
            'resumen' => $documento['resumen'],
            'fecha' => $documento['fecha'] === null ? null : FechaClinica::formato($documento['fecha']),
            'urlImprimir' => ($this->urlImprimir)($documento['clave']),
            'icono' => $documento['icono'],
            'conOpciones' => $documento['tipo'] === 'recipe',
        ], $this->disponibles);
    }

    /** @return array<int,array<string,mixed>> */
    private function recipes(): array
    {
        return array_values(array_filter($this->disponibles, fn (array $documento) => $documento['tipo'] === 'recipe'));
    }
}
