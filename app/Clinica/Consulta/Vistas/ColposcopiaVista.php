<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ColposcopiaDeLaConsulta;
use Illuminate\Support\Collection;

/**
 * **La colposcopía en la sección del examen** (WEB-6.5): el texto del informe, sus plantillas y el guardado
 * explícito.
 *
 * Como los demás ViewModels de la consulta no sabe de base de datos: recibe el texto y las plantillas **ya
 * leídos** (`ColposcopiaDeLaConsulta`) y el `old()` de un POST que falló, y devuelve lo que la vista pinta.
 *
 * Tres cosas que decide esta clase:
 *
 *  - **El texto es uno solo** aunque el escritorio lo guarde en dos columnas (`consultas.observaciones` y la
 *    copia `examen_fisico_nuevo.colposcopia`): acá se muestra el que se lee y el `name` del campo es
 *    `colposcopia`, que es el nombre del examen (y el del control en la pantalla).
 *  - **Las plantillas del escritorio** (`formato_print` tipo `CO`) se ofrecen al lado del campo: al elegir
 *    una se copia el texto ahí (lo hace `consulta.js`) y el médico lo edita antes de guardar.
 *  - **El aviso de cambios sin guardar** es el genérico de la sección (`data-consulta-plantillas`), con el
 *    id del formulario: el mismo camino que la evolución.
 */
final class ColposcopiaVista
{
    /** El `name` del campo del formulario (y su id en el DOM). */
    public const CAMPO = 'colposcopia';

    /**
     * @param  string|null  $texto  el texto guardado (`null` si la consulta no tiene colposcopía)
     * @param  Collection<int,object{codigo:string, titulo:string, texto:string}>  $plantillas
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     */
    public function __construct(
        private ?string $texto,
        private Collection $plantillas,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlImprimir = '',
        private array $old = [],
    ) {
    }

    public function titulo(): string
    {
        return 'Colposcopía';
    }

    public function icono(): string
    {
        return PestanasDelExamen::icono(PestanasDelExamen::COLPOSCOPIA);
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** La hoja del informe (la imprime el motor de documentos de la consulta). */
    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /**
     * ¿Hay algo que imprimir? Es la regla de `QueEsUnDocumento::colposcopia` dicha en pantalla: sin texto no
     * se ofrece la hoja (una colposcopía en blanco no es el informe de nadie).
     */
    public function esUnDocumento(): bool
    {
        return \App\Clinica\Consulta\QueEsUnDocumento::colposcopia($this->texto);
    }

    /** El nombre del control, que es también el del campo que espera el `POST`. */
    public function campo(): string
    {
        return self::CAMPO;
    }

    /**
     * El texto que muestra el campo: lo que se acaba de escribir (`old()`, para no borrar nada ante un error
     * —guía de UI §3—) o lo guardado.
     */
    public function valor(): string
    {
        if (array_key_exists(self::CAMPO, $this->old) && $this->old[self::CAMPO] !== null) {
            return (string) $this->old[self::CAMPO];
        }

        return (string) ($this->texto ?? '');
    }

    /** El marcador del campo vacío. */
    public function marcador(): string
    {
        return 'El hallazgo colposcópico: el epitelio, la zona de transformación, los hallazgos anormales…';
    }

    public function ayuda(): string
    {
        return 'El informe de la colposcopía: el texto que se imprime en la hoja «INFORME DE COLPOSCOPÍA», '
            . 'con el membrete y (si cargaste imágenes en esta consulta) sus fotos. Se guarda con el botón.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la colposcopía. Si sales ahora, se pierden.';
    }

    /**
     * Las plantillas del escritorio, listas para pintar.
     *
     * @return array<int,array{titulo:string, texto:string}>
     */
    public function plantillas(): array
    {
        return $this->plantillas
            ->map(fn (object $plantilla) => ['titulo' => (string) $plantilla->titulo, 'texto' => (string) $plantilla->texto])
            ->all();
    }

    public function hayPlantillas(): bool
    {
        return $this->plantillas->isNotEmpty();
    }

    /** ¿La consulta no tiene ninguna plantilla cargada de este tipo? La vista lo dice en una línea. */
    public function sinPlantillas(): bool
    {
        return ! $this->hayPlantillas();
    }

    /** ¿La consulta ya tiene colposcopía cargada? */
    public function cargada(): bool
    {
        return trim((string) $this->texto) !== '';
    }

    /**
     * El estado de la solapa como pastilla: no dice "guardado" (de eso se encarga el aviso de cambios sin
     * guardar).
     *
     * @return array{tono:string, icono:string, texto:string, titulo:string}
     */
    public function estado(): array
    {
        return $this->cargada()
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada',
                'titulo' => 'Esta consulta ya tiene su informe de colposcopía']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay colposcopía en esta consulta'];
    }
}
