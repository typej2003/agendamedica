<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\DistribuidorDeRecipe;
use App\Clinica\Impresion\DocumentoDeRecipe;
use App\Clinica\Impresion\ItemImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Clinica\Impresion\ProximaCitaDelRecipe;
use App\Support\FechaClinica;

/**
 * **La vista previa del récipe** (WEB-5.2): las hojas A4 horizontales tal como las imprime el navegador, ya
 * repartidas y con todos los textos formateados.
 *
 * Una hoja es una página A4 horizontal con dos mitades; cada mitad es un récipe completo (membrete, datos del
 * paciente, cuerpo, próxima cita, firma, sello y pie). Si los medicamentos no entran en una hoja el récipe sigue
 * en otra (`DistribuidorDeRecipe`), y cada hoja repite el membrete y la firma. Con varios récipes (la secretaría
 * puede imprimir "todos") las hojas van una detrás de otra.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe el membrete, las opciones, los récipes y
 * la próxima cita ya leídos. Se prueba sin base de datos.
 */
final class HojasDeRecipeVista
{
    /**
     * @param  array<int,DocumentoDeRecipe>  $documentos
     * @param  array{fecha:string, hora:?string}|null  $proximaCita
     * @param  array{volver:string, volverTexto:string}  $urls
     */
    public function __construct(
        private Membrete $membrete,
        private OpcionesDeRecipe $opciones,
        private array $documentos,
        private ?array $proximaCita,
        private array $urls,
        private OpcionesDeImpresionVista $dialogo,
        private DistribuidorDeRecipe $distribuidor = new DistribuidorDeRecipe(),
    ) {
    }

    public function titulo(): string
    {
        $primero = $this->documentos[0] ?? null;

        if ($primero === null) {
            return 'Récipe';
        }

        return count($this->documentos) === 1
            ? $primero->titulo . ' · ' . $primero->persona['nombre']
            : 'Récipes de ' . $primero->persona['nombre'];
    }

    public function membrete(): Membrete
    {
        return $this->membrete;
    }

    public function dialogo(): OpcionesDeImpresionVista
    {
        return $this->dialogo;
    }

    /** ¿Se ofrece el botón «Opciones» de la hoja? Siempre: es la página de un récipe (la de varios documentos es `HojasImpresasVista`). */
    public function conOpciones(): bool
    {
        return true;
    }

    public function urlVolver(): string
    {
        return $this->urls['volver'];
    }

    public function textoVolver(): string
    {
        return $this->urls['volverTexto'];
    }

    public function resumenDeLasOpciones(): string
    {
        return $this->dialogo->resumen();
    }

    /**
     * Qué se va a imprimir, en una frase para la barra: cuántos récipes, cuántos medicamentos y en cuántas hojas.
     */
    public function resumenDeLaImpresion(): string
    {
        $hojas = count($this->hojas());
        $medicamentos = array_sum(array_map(fn (DocumentoDeRecipe $documento) => count($documento->items), $this->documentos));
        $recipes = count($this->documentos);

        return ($recipes === 1 ? 'Un récipe' : $recipes . ' récipes') . ' · '
            . $medicamentos . ($medicamentos === 1 ? ' medicamento' : ' medicamentos') . ' · '
            . $hojas . ($hojas === 1 ? ' hoja' : ' hojas');
    }

    /**
     * Las hojas, en el orden en que salen de la impresora.
     *
     * Cada hoja trae su `etiqueta` ("Hoja 1 de 2", sólo si el récipe sigue en otra), el `tamano` de la letra del
     * cuerpo en puntos y sus `mitades`: una o dos, cada una con su título, los datos del paciente, las filas y la
     * próxima cita.
     *
     * @return array<int,array{etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        $hojas = [];

        foreach ($this->documentos as $documento) {
            $reparto = $this->distribuidor->distribuir($this->membrete, $documento, $this->opciones);
            $total = count($reparto['hojas']);

            foreach ($reparto['hojas'] as $posicion => $indices) {
                $items = array_map(fn (int $indice) => $documento->items[$indice], $indices);
                $etiqueta = $total > 1 ? 'Hoja ' . ($posicion + 1) . ' de ' . $total : null;

                $mitades = [];
                if ($this->opciones->conRpEIndicaciones()) {
                    $mitades[] = $this->mitadRp($documento, $items, $etiqueta);
                }
                $mitades[] = $this->mitadIndicaciones($documento, $items, $etiqueta);

                $hojas[] = ['etiqueta' => $etiqueta, 'tamano' => $reparto['puntos'], 'mitades' => $mitades];
            }
        }

        return $hojas;
    }

    // ------------------------------------------------------------------------------------------ Mitades

    /**
     * @param  array<int,ItemImpreso>  $items
     * @return array<string,mixed>
     */
    private function mitadRp(DocumentoDeRecipe $documento, array $items, ?string $etiqueta): array
    {
        return $this->mitad('rp', 'Rp.', $documento, $etiqueta, true, array_map(
            fn (ItemImpreso $item) => [
                'cantidad' => $item->cantidad === null ? '' : (string) $item->cantidad,
                'nombre' => $item->nombre($this->opciones->esGenerico()),
            ],
            $items,
        ));
    }

    /**
     * @param  array<int,ItemImpreso>  $items
     * @return array<string,mixed>
     */
    private function mitadIndicaciones(DocumentoDeRecipe $documento, array $items, ?string $etiqueta): array
    {
        return $this->mitad('indicaciones', 'Indicaciones:', $documento, $etiqueta, false, array_map(
            fn (ItemImpreso $item) => [
                'nombre' => $item->nombre($this->opciones->esGenerico()),
                'indicaciones' => $item->indicaciones,
            ],
            $items,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $filas
     * @return array<string,mixed>
     */
    private function mitad(string $clase, string $titulo, DocumentoDeRecipe $documento, ?string $etiqueta, bool $conDireccion, array $filas): array
    {
        $persona = $documento->persona;

        return [
            'clase' => $clase,
            'titulo' => $titulo,
            'etiqueta' => $etiqueta,
            'paciente' => $persona['nombre'],
            'cedula' => $persona['cedula'] ?? '',
            // La pareja no tiene edad (no se conoce): el dato no se imprime en vez de quedar en blanco.
            'edad' => $documento->esDeLaPareja() ? null : $this->edad($persona['edad']),
            'fecha' => FechaClinica::formato($documento->fecha),
            // La dirección es sólo de la mitad "Rp." (la farmacia) y sólo si el paciente la tiene.
            'direccion' => $conDireccion && ! $documento->esDeLaPareja() ? ($persona['direccion'] ?? null) : null,
            'filas' => $filas,
            'proximaCita' => $this->proximaCita(),
        ];
    }

    private function edad(?int $edad): string
    {
        return $edad === null ? '' : $edad . ($edad === 1 ? ' año' : ' años');
    }

    /** "Próxima cita: 15/10/2026 10:30" (la hora sólo si la sede agenda por hora), o `null` si no hay ninguna. */
    private function proximaCita(): ?string
    {
        if ($this->proximaCita === null) {
            return null;
        }

        return ProximaCitaDelRecipe::texto($this->proximaCita);
    }
}
