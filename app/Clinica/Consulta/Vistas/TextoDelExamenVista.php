<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextosDelExamen;
use Illuminate\Support\Collection;

/**
 * **La solapa de un texto del examen** (WEB-6.5): la colposcopía, la citología, la histeroscopía y el resultado
 * de exámenes comparten esta pantalla —un campo de texto, sus plantillas del escritorio, el aviso de cambios
 * sin guardar y, si la solapa tiene, sus imágenes y su botón de imprimir— y cambian sólo los datos, que salen
 * del catálogo `TextosDelExamen`.
 *
 * Qué decide el ViewModel y la vista no:
 *
 *  - **El campo**: su `name`/id son la **clave de la solapa** (`colposcopia`, `citologia`, …), el texto que
 *    muestra es lo que se acaba de escribir (`old()`, para no borrar nada ante un error —guía de UI §3—) o lo
 *    guardado, y su marcador y su ayuda son los de esa solapa.
 *  - **Las plantillas**: las del `tipo` de `formato_print` de la solapa; la histeroscopía no tiene y lo dice.
 *  - **La hoja**: sólo la colposcopía y la histeroscopía tienen informe, y se ofrece **si hay texto**
 *    (`QueEsUnDocumento::texto`): un examen en blanco no es el informe de nadie.
 *  - **Las fotos**: sólo la colposcopía y la histeroscopía anexan; el bloque lo arma el parcial compartido
 *    `_imagenes`, que recibe su propio ViewModel.
 *
 * No sabe de `Request` ni de base de datos: recibe el texto y las plantillas ya leídos.
 */
final class TextoDelExamenVista
{
    /**
     * @param  string  $clave  la clave de la solapa en `TextosDelExamen`
     * @param  string|null  $texto  el texto guardado (`null` si la consulta no tiene nada en esa solapa)
     * @param  Collection<int,object{codigo:string, titulo:string, texto:string}>  $plantillas
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     */
    public function __construct(
        private string $clave,
        private ?string $texto,
        private Collection $plantillas,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlImprimir = '',
        private array $old = [],
    ) {
    }

    /** La clave de la solapa: es también el `name` del campo y el prefijo de los ids del DOM. */
    public function clave(): string
    {
        return $this->clave;
    }

    public function titulo(): string
    {
        return TextosDelExamen::titulo($this->clave);
    }

    public function icono(): string
    {
        return TextosDelExamen::icono($this->clave);
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** La hoja del informe, o `''` si esta solapa no tiene (la citología y el resultado de exámenes). */
    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /** ¿Esta solapa tiene hoja? (La colposcopía y la histeroscopía, por ahora.) */
    public function tieneHoja(): bool
    {
        return TextosDelExamen::documento($this->clave) !== null;
    }

    /**
     * ¿Hay algo que imprimir? Es la regla del motor de documentos dicha en pantalla: sin texto no se ofrece la
     * hoja.
     */
    public function esUnDocumento(): bool
    {
        return $this->tieneHoja() && QueEsUnDocumento::texto($this->texto);
    }

    /** El nombre del control, que es también el del campo que espera el `POST`. */
    public function campo(): string
    {
        return $this->clave;
    }

    /**
     * El texto que muestra el campo: lo que se acaba de escribir (`old()`, para no perderlo ante un error) o lo
     * guardado.
     */
    public function valor(): string
    {
        if (array_key_exists($this->clave, $this->old) && $this->old[$this->clave] !== null) {
            return (string) $this->old[$this->clave];
        }

        return (string) ($this->texto ?? '');
    }

    /** El marcador del campo vacío, el de esta solapa. */
    public function marcador(): string
    {
        return TextosDelExamen::marcador($this->clave);
    }

    public function ayuda(): string
    {
        return TextosDelExamen::ayuda($this->clave);
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en ' . mb_strtolower($this->titulo()) . '. Si sales ahora, se pierden.';
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

    /** ¿El médico no tiene ninguna plantilla cargada de este tipo? La vista lo dice en una línea. */
    public function sinPlantillas(): bool
    {
        return ! $this->hayPlantillas();
    }

    /** El `tipo` de `formato_print` de esta solapa (para «Guardar como plantilla»), o null si no tiene. */
    public function tipoDePlantillas(): ?string
    {
        return TextosDelExamen::tipoDePlantillas($this->clave);
    }

    /** ¿Esta solapa tiene plantillas en el escritorio? (La histeroscopía, no.) */
    public function tienePlantillas(): bool
    {
        return TextosDelExamen::tipoDePlantillas($this->clave) !== null;
    }

    /** ¿La consulta ya tiene texto en esta solapa? */
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
        $titulo = TextosDelExamen::titulo($this->clave);

        return $this->cargada()
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada',
                'titulo' => 'Esta consulta ya tiene ' . mb_strtolower($titulo)]
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay ' . mb_strtolower($titulo) . ' en esta consulta'];
    }
}
