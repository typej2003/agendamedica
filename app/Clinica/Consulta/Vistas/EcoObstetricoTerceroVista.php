<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoObstetricoTercero;

/**
 * **El eco obstétrico del segundo/tercer trimestre en la sección de estudio** (WEB-6.2): el mismo ViewModel
 * del primer trimestre con otro mapa, otro título y otro icono.
 *
 * Hereda todo lo demás —el formulario por bloques del mapa, el estado por campos cargados, la regla de "esto
 * es un documento" (la fecha, la FUR y la gesta no cuentan) y el aviso de cambios sin guardar—, porque los
 * dos estudios tienen la misma forma: una fila por consulta con su clave.
 *
 * ⚠️ El rótulo dice «2.º y 3.er trimestre» a propósito: el escritorio usa **una sola tabla y una sola
 * pantalla** para los dos, y lo único que cambia es el informe que se imprime.
 */
final class EcoObstetricoTerceroVista extends EcoObstetricoVista
{
    protected function mapa(): array
    {
        return EcoObstetricoTercero::grupos();
    }

    protected function columnasDelEstudio(): array
    {
        return EcoObstetricoTercero::columnas();
    }

    public function titulo(): string
    {
        return 'Eco obstétrico (2.º y 3.er trimestre)';
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::TERCERO);
    }
}
