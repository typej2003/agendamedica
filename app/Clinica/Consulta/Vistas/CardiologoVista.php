<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;

/**
 * **Los estudios cardiológicos de la consulta** (CAR-2): un ViewModel con la forma de las secciones del internista —
 * pestañas y un formulario de campos del `MapaDelCardiologo` —, con una sola tabla por pestaña.
 *
 * Se llama `$seccion` en el parcial, como el resto de las secciones de la consulta.
 */
final class CardiologoVista
{
    /**
     * @param  array<int,array{clave:string, texto:string, url:string, activa:bool, cargada:bool}>  $pestanas
     * @param  array<string,?string>  $valores  lo guardado, por clave del campo
     * @param  array<string,mixed>  $old  el `old()` del formulario
     */
    public function __construct(
        private string $seccion,
        private string $pestana,
        private array $pestanas,
        private array $valores,
        private string $urlGuardar,
        private string $urlVolver,
        private array $old = [],
    ) {
    }

    public function titulo(): string
    {
        return MapaDelCardiologo::TITULOS[$this->seccion] ?? '';
    }

    public function icono(): string
    {
        return 'bi-heart-pulse';
    }

    /** El id del bloque, para los enlaces y las pruebas. */
    public function id(): string
    {
        return 'consulta-' . $this->seccion;
    }

    public function seccion(): string
    {
        return $this->seccion;
    }

    public function pestana(): string
    {
        return $this->pestana;
    }

    /** Las pestañas, o `[]` si la sección tiene una sola (no se dibuja la barra). */
    public function pestanas(): array
    {
        return count($this->pestanas) > 1 ? $this->pestanas : [];
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function textoGuardar(): string
    {
        return 'Guardar el estudio';
    }

    /**
     * Los campos de la pestaña, listos para pintar: el valor guardado (o el `old()` si la validación falló), su id y el
     * ancho de la columna.
     *
     * @return array<int,array{clave:string, etiqueta:string, id:string, tipo:string, valor:string, unidad:?string, max:?int, columna:string, opciones:array<string,string>, marcado:bool}>
     */
    public function campos(): array
    {
        $salida = [];

        foreach (MapaDelCardiologo::deLaPestana($this->pestana) as $campo) {
            $valor = (string) old($campo['clave'], $this->valores[$campo['clave']] ?? '');

            $salida[] = [
                'clave' => $campo['clave'],
                'etiqueta' => $campo['etiqueta'],
                'id' => 'cardio-' . $campo['clave'],
                'tipo' => $campo['tipo'] ?? 'texto',
                'valor' => $valor,
                'unidad' => $campo['unidad'] ?? null,
                'max' => $campo['max'] ?? null,
                'columna' => ($campo['tipo'] ?? 'texto') === 'parrafo' ? 'col-12' : 'col-md-4',
                'opciones' => $campo['opciones'] ?? [],
                'marcado' => $valor === 'S',
            ];
        }

        return $salida;
    }
}
