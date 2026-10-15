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
 */
final class SeccionDeEstudioVista
{
    public function __construct(
        private string $activa,
        private TiposDeEstudio $tipos,
        private object $estudio,
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

    public function ayuda(): string
    {
        return 'Los estudios ecográficos de la consulta. Cada uno se carga en su formulario, se guarda con '
            . 'su botón y se imprime en media hoja con el membrete de tu consultorio. Los estudios que '
            . 'faltan (el mamarío y el abdominal) llegan con las tareas siguientes de F6.';
    }
}
