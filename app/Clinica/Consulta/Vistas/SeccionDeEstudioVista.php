<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La sección Estudio ecográfico de la consulta** (WEB-6.1, ítem 5 del panel del legado): el contenedor
 * con sus tipos y el estudio que se está mirando.
 *
 * Es deliberadamente delgado, como `SeccionDeSolicitudesVista`: cada tipo tiene su propio ViewModel (hoy
 * `EcoGinecologicoVista`) y su parcial `_estudio-<clave>`. Agregar un tipo es sumar su fila a
 * `TiposDeEstudio`, su ViewModel y su parcial, **sin tocar** ni este contenedor ni el controlador de la
 * consulta más que para armarle el ViewModel. Sin base de datos.
 *
 * También lleva las **plantillas de texto** del estudio activo (revisión de Alexander, 2026-10-17): el
 * controlador las lee con `PlantillasDelEstudio` y el parcial del tipo pinta su botón arriba del campo al que
 * escriben. `null` = el estudio no ofrece plantillas (el ecocardiograma fetal).
 */
final class SeccionDeEstudioVista
{
    public function __construct(
        private string $activa,
        private TiposDeEstudio $tipos,
        private object $estudio,
        private ?array $plantillas = null,
        private ?array $formato = null,
    ) {
    }

    /** La clave del tipo activo (`ginecologico`). */
    public function activa(): string
    {
        return $this->activa;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function tipos(): array
    {
        return $this->tipos->opciones($this->activa);
    }

    /** El parcial del tipo activo. */
    public function parcial(): string
    {
        return 'clinica.consulta._estudio-' . $this->activa;
    }

    /** El ViewModel del tipo activo. */
    public function estudio(): object
    {
        return $this->estudio;
    }

    /**
     * Las plantillas de texto del estudio activo (revisión de Alexander, 2026-10-17): el tipo de `formato_print`
     * que el escritorio usa en esa ventana, la columna del formulario a la que se copian y la lista ya leída; o
     * `null` si el estudio no ofrece plantillas (el ecocardiograma fetal, que tampoco las tiene en el escritorio).
     *
     * Lo arma `PlantillasDelEstudio::de()` en el controlador (es la única lectura de esta sección).
     *
     * @return array{tipos:array<int,string>, etiquetas:array<string,string>, columna:string, nombre:string, lista:array<int,array{titulo:string, texto:string}>}|null
     */
    public function plantillas(): ?array
    {
        return $this->plantillas;
    }

    /**
     * Las **plantillas del formato** del estudio activo (O-17), o `null` si la sección no las ofrece.
     *
     * Lo arma el controlador con `PlantillasDelFormato` y el contenedor pinta el panel arriba del formulario:
     *
     *  - `plantillas`: la lista, cada una con su título y sus `valores` (columna => valor);
     *  - `campos`: las columnas descriptivas del estudio, que son las que se guardan y se completan;
     *  - `bloques`: los títulos de los bloques que toca, para decirlo en la pantalla;
     *  - `queHace`: la frase con la regla (sólo completa los vacíos, no toca las medidas ni las fechas);
     *  - `urlGuardar` y `tipo`: a dónde postea «Guardar como plantilla» y con qué estudio.
     *
     * @return array{plantillas:array<int,array{titulo:string, valores:array<string,string>}>, campos:array<int,string>, bloques:array<int,string>, queHace:string, urlGuardar:string, tipo:string}|null
     */
    public function formato(): ?array
    {
        return $this->formato;
    }

    public function ayuda(): string
    {
        return 'Los estudios ecográficos de la consulta. Cada uno se carga en su formulario, se guarda con '
            . 'su botón y se imprime en media hoja con el membrete de tu consultorio. Los siete del ítem 5 '
            . 'están: el ginecológico, los dos obstétricos, el Doppler, el mamario, el abdominal y el '
            . 'ecocardiograma fetal.';
    }
}
