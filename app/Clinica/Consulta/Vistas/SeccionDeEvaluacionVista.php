<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La sección del examen de la consulta** (WEB-6.5, ítem 4 del panel del legado): el contenedor de sus
 * solapas —la evaluación física de WEB-4.3 y las que agrega WEB-6.5 (hoy la colposcopía)—.
 *
 * Es deliberadamente delgado, como `SeccionDeEstudioVista` y `SeccionDeSolicitudesVista`: cada solapa tiene
 * su propio ViewModel y su parcial `_evaluacion-<clave>`. Agregar una solapa es sumar su fila a
 * `PestanasDelExamen`, su ViewModel y su parcial, **sin tocar** ni este contenedor ni el controlador más que
 * para armarle el ViewModel y su despacho de escritura. Sin base de datos.
 */
final class SeccionDeEvaluacionVista
{
    public function __construct(
        private string $activa,
        private PestanasDelExamen $pestanas,
        private object $vista,
    ) {
    }

    /** La clave de la solapa activa (`evaluacion`). */
    public function activa(): string
    {
        return $this->activa;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas->opciones($this->activa);
    }

    /** El parcial de la solapa activa. */
    public function parcial(): string
    {
        return 'clinica.consulta._evaluacion-' . $this->activa;
    }

    /** El ViewModel de la solapa activa. */
    public function vista(): object
    {
        return $this->vista;
    }
}
