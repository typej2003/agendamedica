<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrtopedia;

/**
 * **La pestaña Ortopedia de la consulta** (Traumatología, 2026-11-09): la plantilla ortopédica del escritorio
 * (`w_consulta_principal.srw`, `tabpage_6`) como pestaña de *Solicitudes*, con las casillas y los textos de la hoja
 * «Calzados Ortopédicos» (`d_ortopedia.srd`).
 *
 * Los campos y sus rótulos son los de `MapaDeLaOrtopedia`; acá se agrupan como en la hoja —calzados, tacón y suela,
 * bordes y aparatos— y se resuelve el valor de cada uno (lo que se está escribiendo si el POST falló, si no lo
 * guardado). Las casillas son `char(1)` con `1`/`0`: el parcial manda `0` en un oculto y `1` en la casilla marcada.
 * Sin base de datos.
 */
final class OrtopediaVista
{
    /** Los grupos de la hoja, en su orden, con las claves del mapa. */
    private const GRUPOS = [
        'Calzados ortopédicos' => ['botas', 'zapatos', 'normal', 'soporte', 'thomas', 'plantilla'],
        'Tacón y suela' => ['tacon_int', 'tacon_ext', 'suela_int', 'suela_ext'],
        'Bordes y suela' => ['borde_int_rect', 'borde_int_abdu', 'suela_normal', 'suela_antirres'],
        'Aparatos y observación' => ['aparatos', 'observacion'],
    ];

    /**
     * @param  array<string,string>  $valores  clave del mapa => valor guardado (`''` si no tiene)
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private array $valores,
        private bool $esReal,
        private string $urlGuardar,
        private string $urlImprimir,
        private array $old = [],
    ) {
    }

    /**
     * Los grupos con sus campos ya resueltos, para recorrerlos en el parcial.
     *
     * @return array<int,array{titulo:string, campos:array<int,array<string,mixed>>}>
     */
    public function grupos(): array
    {
        $campos = [];

        foreach (MapaDeLaOrtopedia::campos() as $campo) {
            $campos[$campo['clave']] = $campo + [
                'valor' => $this->valor($campo['clave']),
                'marcado' => $this->valor($campo['clave']) === '1',
            ];
        }

        $grupos = [];

        foreach (self::GRUPOS as $titulo => $claves) {
            $grupos[] = [
                'titulo' => $titulo,
                'campos' => array_values(array_filter(array_map(fn (string $clave) => $campos[$clave] ?? null, $claves))),
            ];
        }

        return $grupos;
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }



    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    public function estado(): array
    {
        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con plantilla', 'titulo' => 'Esta consulta tiene la plantilla ortopédica cargada']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin plantilla', 'titulo' => 'Esta consulta todavía no tiene la plantilla ortopédica'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la plantilla ortopédica. Si sales ahora, se pierden.';
    }

    public function ayuda(): string
    {
        return 'La plantilla ortopédica de la consulta: el calzado, el tacón y las cuñas, los bordes, los aparatos y la '
            . 'observación. Se guarda con su botón y se imprime en la hoja «Calzados Ortopédicos».';
    }

    /** Lo que se está escribiendo (un POST que falló) manda sobre lo guardado. */
    private function valor(string $clave): string
    {
        $valor = $this->old[$clave] ?? $this->valores[$clave] ?? '';

        return trim((string) $valor);
    }
}
