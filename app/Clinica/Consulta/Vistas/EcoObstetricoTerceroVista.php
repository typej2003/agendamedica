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
 * Desde WEB-11.14 el 2.º y el 3.er trimestre están **separados** (Alexander): éste es el 3.º (`eco_obstetrico_tercer`) y
 * `EcoObstetricoSegundoVista` es el 2.º, sobre la tabla hermana `eco_obstetrico_tercer_2`.
 */
class EcoObstetricoTerceroVista extends EcoObstetricoVista
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
        return 'Eco obstétrico (3.er trimestre)';
    }

    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::TERCERO);
    }
}
