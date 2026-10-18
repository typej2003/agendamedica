<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ExamenPediatrico;

/**
 * **El examen físico de pediatría** (WEB-12.2): la solapa *Examen físico* de la evaluación (`tabpage_30` del escritorio): nueve cuadros por aparatos
 * —con los rótulos de la pantalla, que no son los de sus columnas (`ExamenPediatrico`)— y el resumen, que arma «Actualizar» y es editable. Se guarda solo
 * (`data-autoguardar`, `consulta-barra.js`). No consulta nada: recibe lo leído, las plantillas del resumen y las URLs.
 */
final class ExamenPediatricoVista
{
    /**
     * @param  array<string,?string>  $examen  `ExamenPediatrico::examen()`
     * @param  array<int,array{titulo:string, texto:string}|object>  $plantillas  las plantillas del resumen físico (`RF`)
     * @param  array<string,mixed>  $old  el `old()` del formulario
     */
    public function __construct(
        private array $examen,
        private string $urlGuardar,
        private string $urlResumen,
        private string $urlVolver,
        private array $plantillas = [],
        private array $old = [],
    ) {
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlResumen(): string
    {
        return $this->urlResumen;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function avisoSinGuardar(): string
    {
        return 'Tienes cambios sin guardar en el examen físico. Si sales ahora se pierden.';
    }

    /** @return array<int,array{titulo:string, texto:string}> */
    public function plantillas(): array
    {
        return array_map(fn ($p) => ['titulo' => (string) (is_array($p) ? $p['titulo'] : $p->titulo), 'texto' => (string) (is_array($p) ? $p['texto'] : $p->texto)], $this->plantillas);
    }

    public function ayuda(): string
    {
        return 'Un cuadro por aparato o sistema. «Actualizar» arma el resumen con lo escrito y con el peso, la talla y el perímetro cefálico de las mediciones. '
            . 'Todo se guarda solo.';
    }

    /** @return array<int,array{columna:string, etiqueta:string, valor:string}> */
    public function campos(): array
    {
        $campos = [];

        foreach (ExamenPediatrico::EXAMEN as $columna => $etiqueta) {
            $campos[] = ['columna' => $columna, 'etiqueta' => $etiqueta, 'valor' => $this->valor($columna)];
        }

        return $campos;
    }

    public function resumen(): string
    {
        return $this->valor('fisico_resumen');
    }

    public function cargados(): int
    {
        return count(array_filter($this->examen, fn ($texto) => $texto !== null));
    }

    public function totalCampos(): int
    {
        return count($this->examen);
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->cargados() > 0
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado', 'titulo' => 'Esta consulta ya tiene examen físico']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar', 'titulo' => 'Todavía no hay examen físico en esta consulta'];
    }

    private function valor(string $columna): string
    {
        return array_key_exists($columna, $this->old) ? (string) $this->old[$columna] : (string) ($this->examen[$columna] ?? '');
    }
}
