<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrdenDeIngreso;
use App\Clinica\Consulta\Traumatologia\MapaDelImplante;

/**
 * **La pestaña de una solicitud de traumatología** (T3, 2026-11-10): la solicitud de implante y la orden de ingreso
 * comparten esta vista porque las dos son un formulario de campos del escritorio con sus casillas y sus textos.
 *
 * Los campos y sus grupos vienen del mapa de cada una (`MapaDelImplante`, `MapaDeLaOrdenDeIngreso`); acá se resuelve el
 * valor de cada uno (lo que se está escribiendo si el POST falló, si no lo guardado) y las casillas quedan marcadas con
 * `1`. Sin base de datos.
 */
final class SolicitudDeTraumatologiaVista
{
    /**
     * @param  array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>  $campos
     * @param  array<string,array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>>  $grupos
     * @param  array<string,string>  $valores  clave del mapa => valor guardado (`''` si no tiene)
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private string $titulo,
        private string $icono,
        private array $grupos,
        private array $valores,
        private bool $esReal,
        private string $urlGuardar,
        private string $urlImprimir,
        private string $ayuda,
        private array $old = [],
    ) {
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    public function icono(): string
    {
        return $this->icono;
    }

    /**
     * Los grupos con sus campos ya resueltos, para recorrerlos en el parcial.
     *
     * @return array<int,array{titulo:string, campos:array<int,array<string,mixed>>}>
     */
    public function grupos(): array
    {
        $grupos = [];

        foreach ($this->grupos as $nombre => $campos) {
            $grupos[] = [
                'titulo' => $nombre,
                'campos' => array_map(fn (array $campo) => $campo + [
                    'valor' => $this->valor($campo['clave']),
                    'marcado' => $this->valor($campo['clave']) === '1',
                ], $campos),
            ];
        }

        return $grupos;
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada', 'titulo' => 'La consulta tiene la ' . mb_strtolower($this->titulo) . ' cargada']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar', 'titulo' => 'La consulta todavía no tiene la ' . mb_strtolower($this->titulo)];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la ' . mb_strtolower($this->titulo) . '. Si sales ahora, se pierden.';
    }

    public function ayuda(): string
    {
        return $this->ayuda;
    }

    /** Lo que se está escribiendo (un POST que falló) manda sobre lo guardado. */
    private function valor(string $clave): string
    {
        $valor = $this->old[$clave] ?? $this->valores[$clave] ?? '';

        return trim((string) $valor);
    }

    /** Las dos solicitudes que usan esta vista, ya armadas, para el controlador. */
    public static function implante(array $valores, bool $esReal, string $urlGuardar, string $urlImprimir, array $old = []): self
    {
        return new self(
            MapaDelImplante::TITULO,
            'bi-clipboard2-pulse',
            MapaDelImplante::grupos(),
            $valores,
            $esReal,
            $urlGuardar,
            $urlImprimir,
            'La solicitud de implante de la consulta: la intervención, los componentes, los insumos, el diagnóstico y el plan '
                . 'operatorio, y su texto libre. Se imprime en la hoja «SOLICITUD IMPLANTE».',
            $old,
        );
    }

    public static function ordenDeIngreso(array $valores, bool $esReal, string $urlGuardar, string $urlImprimir, array $old = []): self
    {
        return new self(
            MapaDeLaOrdenDeIngreso::TITULO,
            'bi-hospital',
            MapaDeLaOrdenDeIngreso::grupos(),
            $valores,
            $esReal,
            $urlGuardar,
            $urlImprimir,
            'La orden de ingreso de la consulta: la intervención, el diagnóstico, las recomendaciones (las escritas y las '
                . 'casillas de la hoja) y las observaciones. Se imprime en la hoja «ORDEN DE INGRESO».',
            $old,
        );
    }
}
