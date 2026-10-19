<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La sección *Exámenes/Estudios* de la consulta pediátrica** (ítem 6 de su árbol, 2026-10-19): el contenedor de
 * sus tres solapas —*Exámenes*, *Imagenología* y *Estudios exploratorios*—, que en el escritorio son `tab_3` de
 * `tabpage_signos` (`w_consulta_principal.srw:1650-1670`).
 *
 * Es deliberadamente delgado, como `SeccionDeEvaluacionVista` y `SeccionDeSolicitudesVista`: cada solapa tiene su
 * propio ViewModel (`TextoDelExamenVista`) y su parcial (`_evaluacion-texto`, el mismo de las solapas de texto del
 * examen, porque es la misma pantalla: un texto con sus plantillas). Lo que cambia es **de quién es la sección**:
 * acá el ítem del panel es el 6 y no el 4, y por eso el menú, el encabezado y la dirección son los suyos. Es lo
 * que Alexander vio distinto: en el escritorio *Exámenes/Estudios* **no** es una solapa de *Evaluación física*.
 *
 * Sin base de datos.
 */
final class SeccionDeExamenesEstudiosVista
{
    public function __construct(
        private string $activa,
        private PestanasDeExamenesEstudios $pestanas,
        private object $vista,
    ) {
    }

    /** La clave de la solapa activa (`examenes`, `imagenologia` o `exploratorios`). */
    public function activa(): string
    {
        return $this->activa;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas->opciones($this->activa);
    }

    /** El parcial de la solapa activa: las tres son la misma pantalla de texto. */
    public function parcial(): string
    {
        return 'clinica.consulta._evaluacion-texto';
    }

    /** El ViewModel de la solapa activa. */
    public function vista(): object
    {
        return $this->vista;
    }
}
