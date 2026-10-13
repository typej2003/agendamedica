<?php

namespace App\Clinica\Embarazo\Vistas;

/**
 * **La sección del control de embarazo de la consulta** (WEB-6.6, ítem 6 del panel del legado): el
 * contenedor con sus pestañas y la que se está mirando.
 *
 * Es deliberadamente delgado, como `SeccionDeEstudioVista` y `SeccionDeSolicitudesVista`: cada pestaña tiene
 * su propio ViewModel (hoy `PrenatalVista`) y su parcial `_embarazo-<clave>`. Agregar una pestaña es sumar
 * su fila a `PestanasDelEmbarazo`, su ViewModel y su parcial, **sin tocar** este contenedor. Sin base de
 * datos.
 */
final class SeccionDeEmbarazoVista
{
    public function __construct(
        private string $activa,
        private PestanasDelEmbarazo $pestanas,
        private object $pestana,
    ) {
    }

    /** La clave de la pestaña activa (`prenatal`). */
    public function activa(): string
    {
        return $this->activa;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas->opciones($this->activa);
    }

    /** El parcial de la pestaña activa. */
    public function parcial(): string
    {
        return 'clinica.consulta._embarazo-' . $this->activa;
    }

    /** El ViewModel de la pestaña activa. */
    public function pestana(): object
    {
        return $this->pestana;
    }

    public function ayuda(): string
    {
        return 'El control del embarazo: las gestas con su FUR, su edad gestacional y su FPP (pestaña '
            . '*Prenatal*), los exámenes de laboratorio y los controles por fecha. El laboratorio, los '
            . 'controles y el reporte llegan con el resto de esta tarea.';
    }
}
