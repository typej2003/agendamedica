<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Eco renal** (nefrología pediátrica, 2026-10-19): el formulario del eco, que el escritorio abre desde el panel de
 * **Indicadores incidentes**. Los grupos y los campos vienen de `EcoRenal`; acá sólo se les pone su valor.
 */
final class EcoRenalVista
{
    /**
     * @param  array<string,array<string,string>>  $grupos  grupo => [clave => etiqueta]
     * @param  array<string,array{etiqueta:string, clase:string}>  $tipos  clave => su clase (`fecha`, `texto`, `parrafo`)
     * @param  array<string,?string>  $valores  los valores guardados (más lo que haya en `old`)
     * @param  array<string,mixed>  $old
     */
    public function __construct(
        private array $grupos,
        private array $tipos,
        private array $valores,
        private string $urlGuardar,
        private string $urlVolver = '',
        private array $old = [],
        private bool $cargada = false,
    ) {
    }

    /**
     * Los grupos con sus campos ya resueltos: `[['titulo', 'campos' => [['clave','etiqueta','clase','valor'], …]], …]`.
     *
     * @return array<int,array{titulo:string, campos:array<int,array{clave:string, etiqueta:string, clase:string, valor:string}>}>
     */
    public function grupos(): array
    {
        $salida = [];

        foreach ($this->grupos as $titulo => $campos) {
            $filas = [];

            foreach ($campos as $clave => $etiqueta) {
                $filas[] = [
                    'clave'    => $clave,
                    'etiqueta' => $etiqueta,
                    'clase'    => $this->tipos[$clave]['clase'] ?? 'texto',
                    'valor'    => $this->valor($clave),
                ];
            }

            $salida[] = ['titulo' => $titulo, 'campos' => $filas];
        }

        return $salida;
    }

    public function valor(string $clave): string
    {
        if (array_key_exists($clave, $this->old)) {
            return trim((string) $this->old[$clave]);
        }

        return (string) ($this->valores[$clave] ?? '');
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function cargada(): bool
    {
        return $this->cargada;
    }

    public function titulo(): string
    {
        return 'Eco renal';
    }

    public function estado(): array
    {
        return $this->cargada
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado',
                'titulo' => 'La consulta ya tiene el eco renal guardado']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay eco renal en esta consulta'];
    }

    public function ayuda(): string
    {
        return 'El eco renal, con las medidas de los dos riñones, la conclusión y la sugerencia. Se guarda '
            . 'en la consulta y se vuelve a mostrar cuando la abrís de nuevo.';
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay datos escritos sin guardar en el eco renal. Si sales ahora, se pierden.';
    }
}
