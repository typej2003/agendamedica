<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ExamenPediatrico;

/**
 * **El cuestionario por aparatos** (WEB-12.2): la solapa *Cuestionario* de la evaluación de pediatría (`tabpage_31` del escritorio): once cuadros de texto
 * —digestivo, respiratorio, circulatorio, músculo-esquelético, nervioso, urinario, reproductor, sentidos, dentición, lactancia y otros— que se guardan
 * solos. El escritorio **no** tiene plantillas para esta pestaña: la web tampoco las inventa. No consulta nada.
 */
final class CuestionarioVista
{
    /**
     * @param  array<string,?string>  $cuestionario  `ExamenPediatrico::cuestionario()`
     * @param  array<string,mixed>  $old  el `old()` del formulario
     */
    public function __construct(
        private array $cuestionario,
        private string $urlGuardar,
        private string $urlVolver,
        private array $old = [],
    ) {
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function avisoSinGuardar(): string
    {
        return 'Tienes cambios sin guardar en el cuestionario. Si sales ahora se pierden.';
    }

    public function ayuda(): string
    {
        return 'Lo que cuentan los padres, por aparatos: escribe sólo lo que tenga algo que decir. Todo se guarda solo.';
    }

    /** @return array<int,array{columna:string, etiqueta:string, valor:string}> */
    public function campos(): array
    {
        $campos = [];

        foreach (ExamenPediatrico::CUESTIONARIO as $columna => $etiqueta) {
            $campos[] = [
                'columna' => $columna,
                'etiqueta' => $etiqueta,
                'valor' => array_key_exists($columna, $this->old) ? (string) $this->old[$columna] : (string) ($this->cuestionario[$columna] ?? ''),
            ];
        }

        return $campos;
    }

    public function cargados(): int
    {
        return count(array_filter($this->cuestionario, fn ($texto) => $texto !== null));
    }

    public function totalCampos(): int
    {
        return count($this->cuestionario);
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->cargados() > 0
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado', 'titulo' => 'Esta consulta ya tiene cuestionario']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar', 'titulo' => 'Todavía no hay cuestionario en esta consulta'];
    }
}
