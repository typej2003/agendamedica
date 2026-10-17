<?php

namespace App\Clinica\Consulta\Vistas;

/** **El eco obstétrico del 2.º trimestre** (WEB-11.14): el del 3.º con su propio título (misma forma, otra tabla). */
final class EcoObstetricoSegundoVista extends EcoObstetricoTerceroVista
{
    public function titulo(): string
    {
        return 'Eco obstétrico (2.º trimestre)';
    }
}
