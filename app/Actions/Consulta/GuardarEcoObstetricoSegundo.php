<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoObstetricoSegundo;

/** **Guardar el eco obstétrico del 2.º trimestre** (WEB-11.14): la regla del 3.º sobre la tabla `eco_obstetrico_tercer_2`. */
final class GuardarEcoObstetricoSegundo extends GuardarEcoObstetricoTercero
{
    public function __construct(EcoObstetricoSegundo $estudio)
    {
        parent::__construct($estudio);
    }

    protected function tabla(): string
    {
        return EcoObstetricoSegundo::TABLA_DEL_SEGUNDO;
    }

    protected function modelo(): string
    {
        return \App\Models\EcoObstetricoSegundo::class;
    }
}
